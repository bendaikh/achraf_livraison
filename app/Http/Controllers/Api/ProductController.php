<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopifyShop;
use App\Services\Shopify\CatalogSyncService;
use App\Services\Shopify\ShopifyOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Produits — catalog synced from the company's Shopify store(s). One row per variant (SKU,
 * price and stock are per variant). Also used by the product picker of the order lines.
 */
class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->resolveCompanyId();
        $q = ProductVariant::query()->forCompany($companyId)
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select('product_variants.*')
            ->with('product.shop:id,shop_domain,shop_name');

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('products.title', 'like', "%{$search}%")
                    ->orWhere('product_variants.title', 'like', "%{$search}%")
                    ->orWhere('product_variants.sku', 'like', "%{$search}%")
                    ->orWhere('product_variants.barcode', 'like', "%{$search}%");
            });
        }
        $status = (string) $request->query('status', '');
        if ($status !== '' && array_key_exists($status, Product::STATUSES)) {
            $q->where('products.status', $status);
        }
        match ((string) $request->query('stock', '')) {
            'in_stock' => $q->where(fn ($w) => $w->where('product_variants.inventory_tracked', false)->orWhere('product_variants.inventory_quantity', '>', 0)),
            'out_of_stock' => $q->where('product_variants.inventory_tracked', true)->where(fn ($w) => $w->whereNull('product_variants.inventory_quantity')->orWhere('product_variants.inventory_quantity', '<=', 0)),
            default => null,
        };
        if ($request->filled('collection')) {
            $q->where('products.collections', 'like', '%'.json_encode((string) $request->query('collection'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'%');
        }
        if ($request->filled('shop_id')) {
            $q->where('products.shopify_shop_id', $request->integer('shop_id'));
        }
        if (! $request->boolean('include_deleted')) {
            $q->whereNull('products.deleted_in_shopify_at');
        }

        $perPage = min(max($request->integer('per_page', 25), 5), 100);
        $page = $q->orderBy('products.title')->orderBy('product_variants.position')->paginate($perPage);

        $base = ProductVariant::query()->forCompany($companyId)->join('products', 'products.id', '=', 'product_variants.product_id')->whereNull('products.deleted_in_shopify_at');

        return response()->json([
            'data' => collect($page->items())->map(fn (ProductVariant $v) => $v->toCatalogArray())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'counts' => [
                'products' => Product::query()->forCompany($companyId)->whereNull('deleted_in_shopify_at')->count(),
                'variants' => (clone $base)->count(),
                'active' => (clone $base)->where('products.status', 'active')->count(),
                'out_of_stock' => (clone $base)->where('product_variants.inventory_tracked', true)->where(fn ($w) => $w->whereNull('product_variants.inventory_quantity')->orWhere('product_variants.inventory_quantity', '<=', 0))->count(),
            ],
            'collections' => $this->collections($companyId),
        ]);
    }

    /** Catalog connection state for the Produits page header (stores, scopes, last sync). */
    public function status(Request $request, ShopifyOAuth $oauth): JsonResponse
    {
        $companyId = $request->user()->resolveCompanyId();
        $shops = ShopifyShop::query()->where('is_active', true)->whereNull('uninstalled_at')
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->get();

        return response()->json([
            'shops' => $shops->map(fn (ShopifyShop $s) => [
                'id' => $s->id,
                'shop_domain' => $s->shop_domain,
                'shop_name' => $s->shop_name,
                'connected' => $s->isInstalled(),
                'has_products_scope' => $s->hasScope('read_products'),
                'has_inventory_scope' => $s->hasScope('read_inventory'),
                'catalog_synced_at' => $s->catalog_synced_at?->toIso8601String(),
                'catalog_sync_error' => $s->catalog_sync_error,
                'products_count' => $s->products()->whereNull('deleted_in_shopify_at')->count(),
            ])->values(),
            'required_scopes' => ['read_products', 'read_inventory'],
            'configured_scopes' => $oauth->scopes(),
        ]);
    }

    public function sync(Request $request, CatalogSyncService $sync): JsonResponse
    {
        $data = $request->validate(['shop_id' => ['nullable', 'integer'], 'full' => ['nullable', 'boolean']]);
        $companyId = $request->user()->resolveCompanyId();
        $shops = ShopifyShop::query()->where('is_active', true)->whereNull('uninstalled_at')
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when(! empty($data['shop_id']), fn ($q) => $q->whereKey($data['shop_id']))
            ->get()->filter(fn (ShopifyShop $s) => $s->isInstalled());

        if ($shops->isEmpty()) {
            return response()->json(['message' => 'Aucune boutique Shopify connectée (Intégrations → Shopify).'], 422);
        }

        $results = [];
        foreach ($shops as $shop) {
            if (! $shop->hasScope('read_products')) {
                $results[] = ['shop' => $shop->shop_domain, 'success' => false, 'message' => 'Autorisation « read_products » manquante : reconnectez la boutique avec les nouveaux droits.'];

                continue;
            }
            try {
                $r = $sync->sync($shop, (bool) ($data['full'] ?? true));
                $results[] = ['shop' => $shop->shop_domain, 'success' => true, 'message' => "{$r['products']} produit(s), {$r['variants']} variante(s) synchronisé(s)."] + $r;
            } catch (\Throwable $e) {
                Log::warning('Catalog sync failed', ['shop' => $shop->shop_domain, 'error' => $e->getMessage()]);
                $results[] = ['shop' => $shop->shop_domain, 'success' => false, 'message' => 'Échec de la synchronisation : '.mb_substr($e->getMessage(), 0, 300)];
            }
        }
        $ok = collect($results)->where('success', true)->count();

        return response()->json([
            'message' => $ok ? 'Catalogue synchronisé.' : 'La synchronisation a échoué.',
            'results' => $results,
        ], $ok ? 200 : 422);
    }

    /** (Re)registers the Shopify webhooks, including products/* and inventory_levels/update. */
    public function registerWebhooks(Request $request, ShopifyOAuth $oauth): JsonResponse
    {
        $companyId = $request->user()->resolveCompanyId();
        $shops = ShopifyShop::query()->where('is_active', true)->whereNull('uninstalled_at')
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->get()
            ->filter(fn (ShopifyShop $s) => $s->isInstalled());
        $errors = [];
        foreach ($shops as $shop) {
            try {
                $oauth->registerWebhooks($shop);
            } catch (\Throwable $e) {
                $errors[] = $shop->shop_domain.' : '.mb_substr($e->getMessage(), 0, 300);
            }
        }
        if ($shops->isEmpty()) {
            return response()->json(['message' => 'Aucune boutique Shopify connectée.'], 422);
        }

        return response()->json(['message' => $errors ? 'Erreur : '.implode(' | ', $errors) : 'Webhooks produits et stock enregistrés.'], $errors ? 422 : 200);
    }

    protected function collections(int $companyId): array
    {
        return Product::query()->forCompany($companyId)->whereNull('deleted_in_shopify_at')->pluck('collections')
            ->flatten()->filter()->unique()->sort()->values()->all();
    }
}

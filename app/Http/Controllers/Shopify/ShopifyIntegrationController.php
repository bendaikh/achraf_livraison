<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ShopifyAppSetting;
use App\Models\ShopifyShop;
use App\Services\Shopify\OrderSyncService;
use App\Services\Shopify\ShopifyOAuth;
use App\Services\Shopify\ShopifyShopDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyIntegrationController extends Controller
{
    public function status(Request $request, ShopifyOAuth $oauth): JsonResponse
    {
        $companyId = $request->user()?->resolveCompanyId();
        $shop = ShopifyShop::query()
            ->where('is_active', true)
            ->whereNull('uninstalled_at')
            ->when($companyId, fn ($q) => $q->where(function ($w) use ($companyId) {
                $w->where('company_id', $companyId)->orWhereNull('company_id');
            }))
            ->latest('installed_at')
            ->first();

        $ordersCount = $shop
            ? Order::query()->where('shopify_shop_id', $shop->id)->count()
            : 0;

        $settings = $oauth->settings();

        return response()->json([
            'configured' => $oauth->isConfigured(),
            'client_id' => $oauth->clientId() ?? '',
            'has_client_secret' => filled($oauth->clientSecret()),
            'scopes' => $oauth->scopes(),
            'api_version' => $oauth->apiVersion(),
            'redirect_uri' => $oauth->redirectUri(),
            'webhook_url' => $oauth->webhookUrl(),
            'connected' => $shop?->isInstalled() ?? false,
            'shop' => $shop ? [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
                'shop_name' => $shop->shop_name,
                'shop_email' => $shop->shop_email,
                'currency' => $shop->currency,
                'scopes' => $shop->grantedScopeList(),
                'installed_at' => $shop->installed_at?->toIso8601String(),
                'last_synced_at' => $shop->last_synced_at?->toIso8601String(),
                'orders_reconciled_at' => $shop->orders_reconciled_at?->toIso8601String(),
                'orders_count' => $ordersCount,
                'capabilities' => $shop->capabilities(),
                'missing_scopes' => $shop->missingScopes(),
                'needs_reconnect' => $shop->missingScopes() !== [],
                'inventory_location_id' => $shop->inventory_location_id,
            ] : null,
            'settings_updated_at' => $settings->updated_at?->toIso8601String(),
        ]);
    }

    public function saveCredentials(Request $request, ShopifyOAuth $oauth): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'message' => 'Seuls les administrateurs peuvent configurer Shopify.',
            ], 403);
        }

        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'scopes' => ['nullable', 'string', 'max:2000'],
            'api_version' => ['nullable', 'string', 'max:20'],
        ]);

        $settings = ShopifyAppSetting::current();
        $secret = trim((string) ($data['client_secret'] ?? ''));

        if ($secret === '' && ! filled($oauth->clientSecret())) {
            return response()->json([
                'message' => 'Le Client secret est obligatoire pour la première configuration.',
            ], 422);
        }

        $requested = trim($data['scopes'] ?? '') ?: \App\Services\Shopify\ShopifyOAuth::DEFAULT_SCOPES;
        $settings->forceFill([
            'client_id' => trim($data['client_id']),
            'scopes' => mb_substr($requested, 0, 255),
            'requested_scopes' => $requested,
            'api_version' => trim($data['api_version'] ?? '') ?: \App\Services\Shopify\ShopifyOAuth::API_VERSION,
        ]);

        if ($secret !== '') {
            $settings->client_secret = $secret;
        }

        $settings->save();

        return response()->json([
            'message' => 'Identifiants Shopify enregistrés.',
            'configured' => $oauth->isConfigured(),
            'client_id' => $oauth->clientId(),
            'has_client_secret' => filled($oauth->clientSecret()),
            'scopes' => $oauth->scopes(),
            'api_version' => $oauth->apiVersion(),
        ]);
    }

    public function connect(Request $request, ShopifyOAuth $oauth): JsonResponse
    {
        if (! $oauth->isConfigured()) {
            return response()->json([
                'message' => 'Configurez d’abord le Client ID et le Client secret Shopify.',
            ], 422);
        }

        $data = $request->validate([
            'shop' => ['required', 'string', 'max:255'],
        ]);

        if (! ShopifyShopDomain::isValid($data['shop'])) {
            return response()->json([
                'message' => 'Domaine boutique invalide. Utilisez votre-boutique.myshopify.com',
            ], 422);
        }

        $shop = ShopifyShopDomain::normalize($data['shop']);
        $state = $oauth->createNonce();
        $request->session()->put('shopify_oauth_state', $state);
        $request->session()->put('shopify_oauth_shop', $shop);

        return response()->json([
            'authorization_url' => $oauth->authorizationUrl($shop, $state),
        ]);
    }

    public function disconnect(): JsonResponse
    {
        $shops = ShopifyShop::query()->where('is_active', true)->get();

        foreach ($shops as $shop) {
            $shop->forceFill([
                'is_active' => false,
                'uninstalled_at' => now(),
                'access_token' => null,
            ])->save();
        }

        return response()->json([
            'message' => 'Boutique Shopify déconnectée.',
        ]);
    }

    public function sync(OrderSyncService $sync): JsonResponse
    {
        $shop = ShopifyShop::query()
            ->where('is_active', true)
            ->whereNull('uninstalled_at')
            ->latest('installed_at')
            ->first();

        if (! $shop || ! $shop->isInstalled()) {
            return response()->json([
                'message' => 'Aucune boutique Shopify connectée.',
            ], 422);
        }

        try {
            $count = $sync->syncRecentOrders($shop, 100);

            return response()->json([
                'message' => "{$count} commande(s) synchronisée(s).",
                'synced' => $count,
                'last_synced_at' => $shop->fresh()->last_synced_at?->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Shopify manual sync failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Échec de la synchronisation des commandes.',
            ], 500);
        }
    }

    public function orders(Request $request): JsonResponse
    {
        $shop = ShopifyShop::query()
            ->where('is_active', true)
            ->whereNull('uninstalled_at')
            ->latest('installed_at')
            ->first();

        if (! $shop) {
            return response()->json(['orders' => []]);
        }

        $orders = Order::query()
            ->where('shopify_shop_id', $shop->id)
            ->latest('shopify_created_at')
            ->limit(25)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'name' => $order->name,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'email' => $order->email,
                'phone' => $order->phone,
                'status' => $order->status,
                'financial_status' => $order->financial_status,
                'fulfillment_status' => $order->fulfillment_status,
                'total_price' => $order->total_price,
                'currency' => $order->currency,
                'shopify_created_at' => $order->shopify_created_at?->toIso8601String(),
            ]);

        return response()->json(['orders' => $orders]);
    }

    public function logs(Request $request): JsonResponse
    {
        $companyId = $request->user()->resolveCompanyId();
        $logs = \App\Models\ShopifySyncLog::query()
            ->where('company_id', $companyId)
            ->when($request->query('direction'), fn ($q, $v) => $q->where('direction', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('entity'), fn ($q, $v) => $q->where('entity_type', $v))
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $logs]);
    }

    public function registerWebhooks(ShopifyOAuth $oauth): JsonResponse
    {
        $shop = $this->activeShop();
        if (! $shop) {
            return response()->json(['message' => 'Aucune boutique Shopify connectée.'], 422);
        }
        try {
            $oauth->registerWebhooks($shop);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Échec : '.mb_substr($e->getMessage(), 0, 300)], 422);
        }

        return response()->json(['message' => 'Webhooks réenregistrés.']);
    }

    public function reconcileNow(): JsonResponse
    {
        $shop = $this->activeShop();
        if (! $shop) {
            return response()->json(['message' => 'Aucune boutique Shopify connectée.'], 422);
        }
        \App\Jobs\ShopifyReconcileJob::dispatch($shop->id, false)->onQueue('shopify');

        return response()->json([
            'message' => 'Synchronisation lancée.',
            'orders_reconciled_at' => $shop->fresh()->orders_reconciled_at?->toIso8601String(),
        ]);
    }

    public function retryLog(Request $request, \App\Models\ShopifySyncLog $log): JsonResponse
    {
        abort_unless((int) $log->company_id === (int) $request->user()->resolveCompanyId(), 404);
        if ($log->status !== 'failed') {
            return response()->json(['message' => 'Seules les lignes en échec peuvent être relancées.'], 422);
        }
        $log->forceFill(['status' => 'pending', 'error' => null])->save();
        if (in_array($log->action, ['order_edit', 'product_push', 'fulfillment'], true)) {
            \App\Jobs\RetryShopifyOutboundJob::dispatch($log->id)->onQueue('shopify');
        } elseif ($log->shopify_shop_id) {
            \App\Jobs\ShopifyReconcileJob::dispatch((int) $log->shopify_shop_id, false)->onQueue('shopify');
        }

        return response()->json(['message' => 'Nouvel essai programmé.']);
    }

    private function activeShop(): ?ShopifyShop
    {
        $companyId = request()->user()?->resolveCompanyId();

        return ShopifyShop::query()
            ->where('is_active', true)
            ->whereNull('uninstalled_at')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->latest('installed_at')
            ->first();
    }
}

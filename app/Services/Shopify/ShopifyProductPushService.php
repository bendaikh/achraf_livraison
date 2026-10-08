<?php

namespace App\Services\Shopify;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopifyShop;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Pushes catalog edits to the company's Shopify store. Flow never creates a
 * product for a Shopify-connected company. Images stay on Shopify (no upload).
 */
class ShopifyProductPushService
{
    public const RECONNECT = 'Nouvelles autorisations Shopify nécessaires — Reconnecter Shopify';

    public function __construct(protected ShopifySyncLogger $logger) {}

    /** @param  array{title?:string, description_html?:string}  $fields */
    public function updateProduct(Product $product, array $fields, User $user): Product
    {
        $shop = $this->shop($product);
        if (! $product->shopify_product_id) {
            throw ValidationException::withMessages(['product' => 'Ce produit n’est pas lié à Shopify. Flow ne crée pas de produit Shopify.']);
        }
        if (! ($shop->capabilities()['products_write'] ?? false)) {
            throw ValidationException::withMessages(['shopify' => self::RECONNECT]);
        }

        $input = ['id' => "gid://shopify/Product/{$product->shopify_product_id}"];
        if (array_key_exists('title', $fields)) {
            $input['title'] = $fields['title'];
        }
        if (array_key_exists('description_html', $fields)) {
            $input['descriptionHtml'] = $fields['description_html'];
        }
        $replay = ['op' => 'product', 'product_id' => $product->id, 'fields' => $fields, 'user_id' => $user->id];

        try {
            $this->client($shop)->graphqlMutation(self::PRODUCT_UPDATE, ['input' => $input], 'productUpdate');
        } catch (ShopifyApiException $e) {
            $this->fail($shop, $product, $replay, $e, $user);
            throw ValidationException::withMessages(['shopify' => $e->getMessage()]);
        }

        if (array_key_exists('title', $fields)) {
            $product->title = $fields['title'];
        }
        if (array_key_exists('description_html', $fields)) {
            $product->description_html = $fields['description_html'];
        }
        $product->shopify_sync_status = 'synced';
        $product->shopify_sync_error = null;
        $product->shopify_synced_at = now();
        $product->save();
        $this->rememberEcho($shop, $product->fresh()->load('variants'));
        $this->success($shop, $product, $replay, $user);

        return $product->fresh();
    }

    /**
     * @param  array{price?:float, compare_at_price?:?float, sku?:?string, barcode?:?string, inventory_quantity?:int}  $fields
     */
    public function updateVariant(ProductVariant $variant, array $fields, User $user): ProductVariant
    {
        $product = $variant->product;
        $shop = $product ? $this->shop($product) : null;
        if (! $product || ! $shop || ! $product->shopify_product_id || ! $variant->shopify_variant_id) {
            throw ValidationException::withMessages(['variant' => 'Cette variante n’est pas liée à Shopify.']);
        }
        if (! ($shop->capabilities()['products_write'] ?? false)) {
            throw ValidationException::withMessages(['shopify' => self::RECONNECT]);
        }

        $wantsStock = array_key_exists('inventory_quantity', $fields);
        if ($wantsStock && ! $user->can('products.edit_stock_shopify')) {
            throw ValidationException::withMessages(['inventory_quantity' => 'Vous n’avez pas le droit de modifier le stock Shopify.']);
        }
        if ($wantsStock && ! ($shop->capabilities()['inventory_write'] ?? false)) {
            throw ValidationException::withMessages(['shopify' => self::RECONNECT]);
        }

        $variantInput = ['id' => "gid://shopify/ProductVariant/{$variant->shopify_variant_id}"];
        if (array_key_exists('price', $fields)) {
            $variantInput['price'] = number_format((float) $fields['price'], 2, '.', '');
        }
        if (array_key_exists('compare_at_price', $fields)) {
            $variantInput['compareAtPrice'] = $fields['compare_at_price'] === null ? null : number_format((float) $fields['compare_at_price'], 2, '.', '');
        }
        if (array_key_exists('barcode', $fields)) {
            $variantInput['barcode'] = $fields['barcode'];
        }
        if (array_key_exists('sku', $fields)) {
            $variantInput['inventoryItem'] = ['sku' => $fields['sku']];
        }

        $replay = ['op' => 'variant', 'variant_id' => $variant->id, 'fields' => $fields, 'user_id' => $user->id];
        $client = $this->client($shop);
        try {
            if (count($variantInput) > 1) {
                $client->graphqlMutation(self::VARIANTS, [
                    'productId' => "gid://shopify/Product/{$product->shopify_product_id}",
                    'variants' => [$variantInput],
                ], 'productVariantsBulkUpdate');
            }
            if ($wantsStock) {
                $client->graphqlMutation(self::INVENTORY, [
                    'input' => [
                        'name' => 'available',
                        'reason' => 'correction',
                        'ignoreCompareQuantity' => true,
                        'quantities' => [[
                            'inventoryItemId' => 'gid://shopify/InventoryItem/'.$variant->shopify_inventory_item_id,
                            'locationId' => 'gid://shopify/Location/'.$shop->inventory_location_id,
                            'quantity' => (int) $fields['inventory_quantity'],
                        ]],
                    ],
                ], 'inventorySetQuantities');
            }
        } catch (ShopifyApiException $e) {
            $this->fail($shop, $product, $replay, $e, $user);
            throw ValidationException::withMessages(['shopify' => $e->getMessage()]);
        }

        foreach (['price', 'compare_at_price', 'sku', 'barcode', 'inventory_quantity'] as $key) {
            if (array_key_exists($key, $fields)) {
                $variant->{$key} = $fields[$key];
            }
        }
        $variant->save();
        $product->forceFill([
            'shopify_sync_status' => 'synced',
            'shopify_sync_error' => null,
            'shopify_synced_at' => now(),
        ])->save();
        $this->rememberEcho($shop, $product->fresh()->load('variants'));
        $this->success($shop, $product, $replay, $user);

        return $variant->fresh();
    }

    public function replay(array $payload, ?User $user): void
    {
        $user ??= User::query()->find($payload['user_id'] ?? 0);
        if (! $user) {
            throw new ShopifyApiException('Utilisateur introuvable pour relancer la modification.', 422, false);
        }
        if (($payload['op'] ?? '') === 'product') {
            $this->updateProduct(Product::query()->findOrFail($payload['product_id']), (array) $payload['fields'], $user);

            return;
        }
        $this->updateVariant(ProductVariant::query()->findOrFail($payload['variant_id']), (array) $payload['fields'], $user);
    }

    protected function shop(Product $product): ShopifyShop
    {
        $shop = $product->shop;
        if (! $shop) {
            throw ValidationException::withMessages(['shopify' => 'Aucune boutique Shopify pour ce produit.']);
        }

        return $shop;
    }

    protected function client(ShopifyShop $shop): ShopifyClient
    {
        return new ShopifyClient($shop->shop_domain, $shop->access_token, app(ShopifyOAuth::class)->apiVersion());
    }

    protected function rememberEcho(ShopifyShop $shop, Product $product): void
    {
        $product->loadMissing('variants');
        $fields = app(CatalogSyncService::class)->echoFields([
            'title' => $product->title,
            'description_html' => $product->description_html,
            'variants' => $product->variants->map(fn (ProductVariant $v) => [
                'shopify_variant_id' => $v->shopify_variant_id,
                'price' => $v->price,
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'compare_at_price' => $v->compare_at_price,
                'inventory_quantity' => $v->inventory_quantity,
            ])->all(),
        ]);
        SyncEcho::remember($shop->id, 'product', (string) $product->shopify_product_id, $fields, $product->shopify_updated_at?->toIso8601String());
    }

    /** @param  array<string, mixed>  $replay */
    protected function success(ShopifyShop $shop, Product $product, array $replay, User $user): void
    {
        $this->logger->log([
            'company_id' => $shop->resolveCompanyId(),
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'shopify_id' => (string) $product->shopify_product_id,
            'action' => 'product_push',
            'source' => 'flow_user',
            'user_id' => $user->id,
            'status' => 'success',
            'request_excerpt' => $replay,
        ]);
    }

    /** @param  array<string, mixed>  $replay */
    protected function fail(ShopifyShop $shop, Product $product, array $replay, ShopifyApiException $e, User $user): void
    {
        $product->forceFill([
            'shopify_sync_status' => 'failed',
            'shopify_sync_error' => mb_substr($e->getMessage(), 0, 500),
        ])->save();
        $this->logger->log([
            'company_id' => $shop->resolveCompanyId(),
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'shopify_id' => (string) $product->shopify_product_id,
            'action' => 'product_push',
            'source' => 'flow_user',
            'user_id' => $user->id,
            'status' => 'failed',
            'error' => $e->getMessage(),
            'attempts' => 1,
            'request_excerpt' => $replay,
        ]);
    }

    private const PRODUCT_UPDATE = <<<'GQL'
mutation productUpdate($input: ProductInput!) {
  productUpdate(input: $input) {
    product { id title }
    userErrors { field message }
  }
}
GQL;

    private const VARIANTS = <<<'GQL'
mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants { id }
    userErrors { field message }
  }
}
GQL;

    private const INVENTORY = <<<'GQL'
mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
  inventorySetQuantities(input: $input) {
    inventoryAdjustmentGroup { reason }
    userErrors { field message }
  }
}
GQL;
}

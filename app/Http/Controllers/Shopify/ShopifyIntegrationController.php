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
    public function status(ShopifyOAuth $oauth): JsonResponse
    {
        $shop = ShopifyShop::query()
            ->where('is_active', true)
            ->whereNull('uninstalled_at')
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
                'scopes' => $shop->scopes,
                'installed_at' => $shop->installed_at?->toIso8601String(),
                'last_synced_at' => $shop->last_synced_at?->toIso8601String(),
                'orders_count' => $ordersCount,
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
            'scopes' => ['nullable', 'string', 'max:500'],
            'api_version' => ['nullable', 'string', 'max:20'],
        ]);

        $settings = ShopifyAppSetting::current();
        $secret = trim((string) ($data['client_secret'] ?? ''));

        if ($secret === '' && ! filled($oauth->clientSecret())) {
            return response()->json([
                'message' => 'Le Client secret est obligatoire pour la première configuration.',
            ], 422);
        }

        $settings->forceFill([
            'client_id' => trim($data['client_id']),
            'scopes' => trim($data['scopes'] ?? '') ?: 'read_orders,read_customers,read_products,read_inventory',
            'api_version' => trim($data['api_version'] ?? '') ?: '2025-01',
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
}

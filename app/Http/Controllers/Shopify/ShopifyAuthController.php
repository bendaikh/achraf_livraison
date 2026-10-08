<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Models\ShopifyShop;
use App\Services\Shopify\OrderSyncService;
use App\Services\Shopify\ShopifyOAuth;
use App\Services\Shopify\ShopifyShopDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyAuthController extends Controller
{
    public function install(Request $request, ShopifyOAuth $oauth): RedirectResponse|JsonResponse
    {
        if (! $oauth->isConfigured()) {
            return redirect('/integrations/shopify?error=not_configured');
        }

        $shopInput = $request->query('shop', $request->input('shop'));
        if (! $shopInput || ! ShopifyShopDomain::isValid($shopInput)) {
            return redirect('/integrations/shopify?error=invalid_shop');
        }

        $shop = ShopifyShopDomain::normalize($shopInput);
        $state = $oauth->createNonce();
        $request->session()->put('shopify_oauth_state', $state);
        $request->session()->put('shopify_oauth_shop', $shop);

        return redirect()->away($oauth->authorizationUrl($shop, $state));
    }

    public function callback(Request $request, ShopifyOAuth $oauth, OrderSyncService $sync): RedirectResponse
    {
        if (! $oauth->isConfigured()) {
            return redirect('/integrations/shopify?error=not_configured');
        }

        $params = $request->query();

        if (! $oauth->verifyQueryHmac($params)) {
            return redirect('/integrations/shopify?error=hmac');
        }

        $state = $request->query('state');
        $expectedState = $request->session()->pull('shopify_oauth_state');
        if (! $state || ! $expectedState || ! hash_equals($expectedState, $state)) {
            return redirect('/integrations/shopify?error=state');
        }

        $shop = ShopifyShopDomain::normalize((string) $request->query('shop'));
        $code = (string) $request->query('code');

        if (! ShopifyShopDomain::isValid($shop) || $code === '') {
            return redirect('/integrations/shopify?error=invalid_shop');
        }

        try {
            $tokenPayload = $oauth->exchangeCode($shop, $code);

            $shopModel = ShopifyShop::query()->updateOrCreate(
                ['shop_domain' => $shop],
                [
                    'access_token' => $tokenPayload['access_token'],
                    'is_active' => true,
                    'installed_at' => now(),
                    'uninstalled_at' => null,
                ]
            );
            $shopModel->rememberScopes($tokenPayload['scope'] ?? $oauth->scopes());
            $shopModel->save();

            try {
                $details = $oauth->fetchShopDetails($shopModel);
                $shopModel->forceFill([
                    'shop_name' => $details['name'] ?? $shopModel->shop_name,
                    'shop_email' => $details['email'] ?? $shopModel->shop_email,
                    'currency' => $details['currency'] ?? $shopModel->currency,
                    'timezone' => $details['iana_timezone'] ?? ($details['timezone'] ?? $shopModel->timezone),
                ])->save();
            } catch (\Throwable $e) {
                Log::warning('Shopify shop details fetch failed', ['error' => $e->getMessage()]);
            }

            try {
                $oauth->registerWebhooks($shopModel);
            } catch (\Throwable $e) {
                Log::error('Shopify webhook registration failed', ['error' => $e->getMessage()]);
            }

            try {
                $sync->syncRecentOrders($shopModel, 50);
            } catch (\Throwable $e) {
                Log::error('Shopify initial order sync failed', ['error' => $e->getMessage()]);
            }

            $request->session()->forget('shopify_oauth_shop');

            return redirect('/integrations/shopify?connected=1');
        } catch (\Throwable $e) {
            Log::error('Shopify OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect('/integrations/shopify?error=oauth');
        }
    }
}

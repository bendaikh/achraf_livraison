<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessShopifyWebhookJob;
use App\Services\Shopify\ShopifyOAuth;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request, ShopifyOAuth $oauth): Response
    {
        $rawBody = $request->getContent();
        $hmac = $request->header('X-Shopify-Hmac-Sha256');

        if (! $oauth->verifyWebhookHmac($rawBody, $hmac)) {
            Log::warning('Shopify webhook HMAC verification failed');

            return response('Invalid HMAC', 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic');
        $shopDomain = strtolower((string) $request->header('X-Shopify-Shop-Domain'));
        $payload = json_decode($rawBody, true) ?? [];

        ProcessShopifyWebhookJob::dispatchSync($topic, $shopDomain, $payload);

        return response('OK', 200);
    }
}

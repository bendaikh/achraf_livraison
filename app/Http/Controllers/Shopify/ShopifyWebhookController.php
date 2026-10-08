<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\ShopifyShop;
use App\Models\ShopifyWebhookEvent;
use App\Services\Shopify\ShopifyOAuth;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
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
        $eventId = $request->header('X-Shopify-Event-Id');
        $webhookId = $request->header('X-Shopify-Webhook-Id')
            ?: $eventId
            ?: hash('sha256', $shopDomain.'|'.$topic.'|'.$rawBody);

        $shop = ShopifyShop::query()->where('shop_domain', $shopDomain)->first();
        $resourceId = ProcessShopifyWebhookJob::resourceId($payload);
        $triggered = $payload['updated_at'] ?? $payload['created_at'] ?? null;

        try {
            $event = ShopifyWebhookEvent::create([
                'company_id' => $shop?->company_id,
                'shopify_shop_id' => $shop?->id,
                'topic' => $topic,
                'webhook_id' => (string) $webhookId,
                'event_id' => $eventId ? (string) $eventId : null,
                'resource_id' => $resourceId,
                'triggered_at' => $triggered,
                'payload' => $payload,
                'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException|QueryException $e) {
            if ($e instanceof QueryException && ! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }

            return response('OK', 200);
        }

        ProcessShopifyWebhookJob::dispatch($topic, $shopDomain, $payload, $event->id)->onQueue('shopify');

        return response('OK', 200);
    }
}

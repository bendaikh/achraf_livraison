<?php

namespace App\Jobs;

use App\Models\ShopifySyncLog;
use App\Models\User;
use App\Services\Shopify\ShopifyApiException;
use App\Services\Shopify\ShopifyFulfillmentService;
use App\Services\Shopify\ShopifyOrderEditService;
use App\Services\Shopify\ShopifyProductPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;

/** Replays one failed outbound Shopify log. Non-retryable errors fail immediately. */
class RetryShopifyOutboundJob implements ShouldQueue
{
    use HandlesShopifyRetries, Queueable;

    public function __construct(public int $logId)
    {
        $this->onQueue('shopify');
    }

    public function handle(
        ShopifyOrderEditService $orders,
        ShopifyProductPushService $products,
        ShopifyFulfillmentService $fulfillments,
    ): void {
        $log = ShopifySyncLog::query()->find($this->logId);
        if (! $log || ! in_array($log->action, ['order_edit', 'product_push', 'fulfillment'], true)) {
            return;
        }
        $payload = json_decode((string) $log->request_excerpt, true);
        if (! is_array($payload)) {
            $log->forceFill(['status' => 'failed', 'error' => 'Rejeu impossible : payload absent.'])->save();

            return;
        }
        $user = $log->user_id ? User::query()->find($log->user_id) : null;

        try {
            match ($log->action) {
                'order_edit' => $orders->replay($payload, $user),
                'product_push' => $products->replay($payload, $user),
                'fulfillment' => $fulfillments->replay($payload, $user),
            };
            $log->forceFill(['status' => 'success', 'error' => null])->save();
        } catch (ShopifyApiException $e) {
            $log->forceFill([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'attempts' => (int) $log->attempts + 1,
            ])->save();
            if ($e->retryable && $this->releaseForShopify($e)) {
                return;
            }
            $this->fail($e);
        } catch (ValidationException $e) {
            $log->forceFill([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'attempts' => (int) $log->attempts + 1,
            ])->save();
            $this->fail($e);
        }
    }
}

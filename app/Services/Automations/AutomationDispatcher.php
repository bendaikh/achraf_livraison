<?php

namespace App\Services\Automations;

use App\Jobs\Automations\ProcessAutomationRunJob;
use App\Models\Automation;
use App\Models\Order;
use App\Models\ShopifyShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bridges domain events to the automation engine without hardcoding scenarios.
 * Call dispatch(triggerType, companyId, subject, payload, idempotencyKey).
 */
class AutomationDispatcher
{
    public function __construct(protected AutomationEngine $engine) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        string $triggerType,
        int $companyId,
        ?Model $subject = null,
        array $payload = [],
        ?string $idempotencyKey = null,
        bool $sync = false,
    ): void {
        $automations = Automation::query()
            ->forCompany($companyId)
            ->active()
            ->where('trigger_type', $triggerType)
            ->get();

        foreach ($automations as $automation) {
            try {
                $key = $idempotencyKey
                    ? "{$idempotencyKey}:auto:{$automation->id}"
                    : null;

                if ($sync || config('queue.default') === 'sync') {
                    $this->engine->start($automation, $subject, $payload, $key, simulate: false);
                } else {
                    ProcessAutomationRunJob::dispatch(
                        $automation->id,
                        $subject ? $subject::class : null,
                        $subject?->getKey(),
                        $payload,
                        $key,
                    );
                }
            } catch (Throwable $e) {
                Log::warning('Automation dispatch failed', [
                    'automation_id' => $automation->id,
                    'trigger' => $triggerType,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** Resolve company_id for an order (shop → company, else default). */
    public function companyIdForOrder(Order $order): int
    {
        if ($order->shopify_shop_id) {
            $shop = $order->relationLoaded('shop')
                ? $order->shop
                : ShopifyShop::query()->find($order->shopify_shop_id);
            if ($shop) {
                return $shop->resolveCompanyId();
            }
        }

        return \App\Models\Company::default()->id;
    }

    public function orderCreated(Order $order): void
    {
        $this->dispatch('order.created', $this->companyIdForOrder($order), $order, [
            'order_id' => $order->id,
        ], "order.created:{$order->id}");
    }

    public function orderUpdated(Order $order, array $changes = []): void
    {
        $this->dispatch('order.updated', $this->companyIdForOrder($order), $order, [
            'order_id' => $order->id,
            'changes' => $changes,
        ], 'order.updated:'.$order->id.':'.md5(json_encode($changes)));
    }

    public function orderStatusChanged(Order $order, ?string $from, ?string $to): void
    {
        $this->dispatch('order.status_changed', $this->companyIdForOrder($order), $order, [
            'order_id' => $order->id,
            'from' => $from,
            'to' => $to,
        ], "order.status_changed:{$order->id}:{$to}");
    }

    public function orderConfirmationChanged(Order $order, ?string $from, ?string $to, array $meta = []): void
    {
        $status = \App\Models\ConfirmationStatus::findByCode($to, $order->company_id);
        $context = [
            'order_id' => $order->id,
            'from' => $from,
            'to' => $to,
            'from_status' => $meta['from_status'] ?? $from,
            'to_status' => $meta['to_status'] ?? $to,
            'to_category' => $meta['to_category'] ?? $status?->category,
            'reason' => $meta['reason'] ?? $order->cancellation_reason,
            'recall_at' => $meta['recall_at'] ?? $order->postponed_until?->toIso8601String(),
        ];
        $companyId = $this->companyIdForOrder($order);

        if ($status?->counts_as_confirmed) {
            $this->dispatch('order.confirmed', $companyId, $order, $context, "order.confirmed:{$order->id}");
        }
        if ($status?->category === \App\Models\ConfirmationStatus::CATEGORY_NO_ANSWER) {
            $this->dispatch('order.no_answer', $companyId, $order, $context, "order.no_answer:{$order->id}");
        }
        if ($status?->category === \App\Models\ConfirmationStatus::CATEGORY_RECALL || $status?->queue_behavior === \App\Models\ConfirmationStatus::BEHAVIOR_FUTURE_ONLY) {
            $this->dispatch('order.postponed', $companyId, $order, $context, "order.postponed:{$order->id}");
        }
        if ($status?->counts_as_failure) {
            $this->dispatch('order.cancelled', $companyId, $order, $context, "order.cancelled:{$order->id}");
        }

        $this->dispatch('order.confirmation_changed', $companyId, $order, $context, "order.confirmation_changed:{$order->id}:{$to}");
    }
}

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

    public function orderConfirmationChanged(Order $order, ?string $from, ?string $to): void
    {
        $map = [
            Order::CONFIRMATION_CONFIRMED => 'order.confirmed',
            Order::CONFIRMATION_NO_ANSWER => 'order.no_answer',
            Order::CONFIRMATION_POSTPONED => 'order.postponed',
            Order::CONFIRMATION_CANCELLED => 'order.cancelled',
        ];
        if ($trigger = $map[$to] ?? null) {
            $this->dispatch($trigger, $this->companyIdForOrder($order), $order, [
                'order_id' => $order->id,
                'from' => $from,
                'to' => $to,
            ], "{$trigger}:{$order->id}");
        }
        $this->dispatch('order.confirmation_changed', $this->companyIdForOrder($order), $order, [
            'order_id' => $order->id,
            'from' => $from,
            'to' => $to,
        ], "order.confirmation_changed:{$order->id}:{$to}");
    }
}

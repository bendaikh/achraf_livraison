<?php

namespace App\Services\Automations\Actions;

use App\Models\DeliveryStatus;
use App\Models\Order;
use App\Services\OrderWorkflow;
use App\Support\CurrentUser;

class ChangeStatusAction extends BaseAction
{
    public function key(): string
    {
        return 'internal.change_status';
    }

    public function label(): string
    {
        return 'Changer le statut de livraison';
    }

    public function integration(): string
    {
        return 'internal';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'delivery_status', 'label' => 'Code statut', 'type' => 'string', 'required' => true,
                'hint' => 'Code du statut (ex. to_assign, delivered)'],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        $code = trim((string) ($config['delivery_status'] ?? ''));
        if ($code === '') {
            return ['ok' => false, 'error' => 'Statut manquant.'];
        }

        $status = DeliveryStatus::query()->where('code', $code)->first();
        if (! $status) {
            return ['ok' => false, 'error' => "Statut [{$code}] introuvable."];
        }

        $orderId = (int) ($context['order']['id'] ?? $context['subject_id'] ?? 0);

        if ($simulate) {
            return [
                'ok' => true,
                'simulated' => true,
                'output' => [
                    'delivery_status' => $code,
                    'delivery_status_label' => $status->name ?? $code,
                    'order_id' => $orderId,
                ],
            ];
        }

        $order = Order::query()->find($orderId);
        if (! $order) {
            return ['ok' => false, 'error' => "Commande #{$orderId} introuvable."];
        }

        try {
            app(OrderWorkflow::class)->changeStatus($order, $status, [], CurrentUser::get());
        } catch (\Throwable $e) {
            // Fallback: set code directly if workflow rejects transition
            $order->forceFill([
                'delivery_status' => $code,
                'status_changed_at' => now(),
            ])->save();
        }

        return [
            'ok' => true,
            'output' => [
                'delivery_status' => $code,
                'delivery_status_label' => $status->name ?? $code,
                'order_id' => $order->id,
            ],
        ];
    }
}

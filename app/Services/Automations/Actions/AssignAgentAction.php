<?php

namespace App\Services\Automations\Actions;

class AssignAgentAction extends BaseAction
{
    public function key(): string
    {
        return 'internal.assign_agent';
    }

    public function label(): string
    {
        return 'Affecter un agent';
    }

    public function integration(): string
    {
        return 'internal';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'user_id', 'label' => 'ID agent', 'type' => 'number', 'required' => true],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        $userId = (int) ($config['user_id'] ?? 0);
        $orderId = (int) ($context['order']['id'] ?? 0);
        if ($simulate || ! $orderId) {
            return ['ok' => true, 'simulated' => true, 'output' => ['user_id' => $userId, 'order_id' => $orderId]];
        }
        $order = \App\Models\Order::query()->find($orderId);
        if (! $order) {
            return ['ok' => false, 'error' => 'Commande introuvable.'];
        }
        $order->forceFill(['assigned_user_id' => $userId ?: null, 'assigned_at' => now()])->save();

        return ['ok' => true, 'output' => ['user_id' => $userId, 'order_id' => $order->id]];
    }
}

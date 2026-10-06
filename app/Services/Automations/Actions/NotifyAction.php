<?php

namespace App\Services\Automations\Actions;

class NotifyAction extends BaseAction
{
    public function key(): string
    {
        return 'internal.notify';
    }

    public function label(): string
    {
        return 'Notification interne';
    }

    public function integration(): string
    {
        return 'internal';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
            ['key' => 'channel', 'label' => 'Canal', 'type' => 'select', 'options' => [
                ['value' => 'log', 'label' => 'Log'],
                ['value' => 'user', 'label' => 'Utilisateur'],
            ], 'default' => 'log'],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        $message = (string) ($config['message'] ?? '');
        if (! $simulate) {
            \Illuminate\Support\Facades\Log::info('[Automation notify] '.$message, [
                'order_id' => $context['order']['id'] ?? null,
            ]);
        }

        return ['ok' => true, 'simulated' => $simulate, 'output' => ['message' => $message]];
    }
}

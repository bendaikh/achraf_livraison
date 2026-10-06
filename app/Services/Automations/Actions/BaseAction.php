<?php

namespace App\Services\Automations\Actions;

use App\Services\Automations\Contracts\AutomationAction;

/** Base helper for stub / real actions. */
abstract class BaseAction implements AutomationAction
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function integration(): string;

    public function configSchema(): array
    {
        return [];
    }

    /** Stub result used when the real integration is not wired yet. */
    protected function stub(array $config, bool $simulate, string $message = 'Action simulée (stub)'): array
    {
        return [
            'ok' => true,
            'simulated' => true,
            'output' => [
                'stub' => true,
                'message' => $message,
                'config' => $config,
                'would_execute' => ! $simulate ? 'pending_integration' : 'simulation',
            ],
        ];
    }
}

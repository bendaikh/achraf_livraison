<?php

namespace App\Services\Automations\Actions;

class CarrierRefreshTrackingAction extends BaseAction
{
    public function __construct(protected string $carrierKey, protected string $carrierLabel) {}

    public function key(): string
    {
        return "{$this->carrierKey}.refresh_tracking";
    }

    public function label(): string
    {
        return "{$this->carrierLabel} · Actualiser tracking / statut";
    }

    public function integration(): string
    {
        return $this->carrierKey;
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'tracking_number', 'label' => 'Tracking', 'type' => 'string'],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        return $this->stub($config, $simulate, "{$this->carrierLabel} refresh_tracking (stub)");
    }
}

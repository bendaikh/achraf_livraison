<?php

namespace App\Services\Automations\Actions;

class CarrierCreateParcelAction extends BaseAction
{
    public function __construct(protected string $carrierKey, protected string $carrierLabel) {}

    public function key(): string
    {
        return "{$this->carrierKey}.create_parcel";
    }

    public function label(): string
    {
        return "{$this->carrierLabel} · Créer un colis";
    }

    public function integration(): string
    {
        return $this->carrierKey;
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'order_id', 'label' => 'Commande', 'type' => 'string', 'hint' => '{{order.id}}'],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        $out = $this->stub($config, $simulate, "{$this->carrierLabel} create_parcel (stub)");
        $out['output']['trackingNumber'] = $simulate ? 'SIM-TRACK-001' : null;

        return $out;
    }
}

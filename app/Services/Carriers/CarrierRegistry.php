<?php

namespace App\Services\Carriers;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/** Registry of the delivery companies listed in config/carriers.php. */
class CarrierRegistry
{
    /** @var array<string, CarrierInterface>|null */
    protected ?array $carriers = null;

    /** @return array<string, CarrierInterface> */
    public function all(): array
    {
        if ($this->carriers === null) {
            $this->carriers = [];
            foreach ((array) config('carriers.drivers', []) as $key => $class) {
                $carrier = app($class);
                if ($carrier instanceof CarrierInterface) {
                    $this->carriers[$carrier->key()] = $carrier;
                }
            }
        }

        return $this->carriers;
    }

    public function get(string $key): ?CarrierInterface
    {
        return $this->all()[$key] ?? null;
    }

    /** For the UI: every registered carrier with its availability for the company. */
    public function options(int $companyId): array
    {
        return array_values(array_map(function (CarrierInterface $c) use ($companyId) {
            $reason = $c->unavailableReason($companyId);

            return ['key' => $c->key(), 'label' => $c->label(), 'color' => $c->color(), 'available' => $reason === null, 'reason' => $reason];
        }, $this->all()));
    }

    /** First active external shipment of the order (any carrier). */
    public function shipmentFor(Order $order): ?array
    {
        foreach ($this->all() as $carrier) {
            if ($s = $carrier->shipmentFor($order)) {
                return $s;
            }
        }

        return null;
    }

    /** Filter: "local" (driver, no external parcel), "none" (neither), "external" (any carrier) or a carrier key. */
    public function applyFilter(Builder $q, string $value): void
    {
        if ($value === 'local') {
            $q->whereNotNull('driver_id');
            foreach ($this->all() as $c) {
                $q->whereNot(fn ($w) => $c->scopeShipped($w));
            }

            return;
        }
        if ($value === 'none') {
            $q->whereNull('driver_id');
            foreach ($this->all() as $c) {
                $q->whereNot(fn ($w) => $c->scopeShipped($w));
            }

            return;
        }
        if ($value === 'external') {
            $q->where(function ($w) {
                foreach ($this->all() as $c) {
                    $w->orWhere(fn ($x) => $c->scopeShipped($x));
                }
            });

            return;
        }
        if ($carrier = $this->get($value)) {
            $carrier->scopeShipped($q);

            return;
        }
        $q->whereRaw('1 = 0');
    }
}

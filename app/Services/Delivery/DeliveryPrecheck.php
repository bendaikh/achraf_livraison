<?php

namespace App\Services\Delivery;

use App\Models\Order;
use App\Services\Carriers\AdvancedCarrier;
use App\Services\Carriers\CarrierInterface;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Speedaf\SpeedafShipmentService;
use Illuminate\Support\Collection;

/**
 * Local validation before a carrier send. Never calls the carrier HTTP API:
 * AdvancedCarrier::preview() is already local, and every other carrier goes through
 * the same checks ship() can decide without leaving the database.
 */
class DeliveryPrecheck
{
    public function __construct(protected CarrierRegistry $carriers) {}

    /**
     * @param  Collection<int, Order>  $orders  keyed by id
     * @param  list<int>  $ids  requested order, preserved
     * @return list<array{order_id:int,reference:string,amount_due:float,can_send:bool,errors:list<string>,reason:?string}>
     */
    public function rows(CarrierInterface $carrier, int $companyId, Collection $orders, array $ids): array
    {
        if ($carrier instanceof AdvancedCarrier) {
            return $this->fromPreview($carrier, $companyId, $orders, $ids);
        }

        $rows = [];
        foreach ($ids as $id) {
            $order = $orders->get($id);
            $rows[] = $order
                ? $this->genericRow($carrier, $order)
                : $this->missingRow((int) $id);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  list<int>  $ids
     */
    protected function fromPreview(AdvancedCarrier $carrier, int $companyId, Collection $orders, array $ids): array
    {
        $preview = [];
        foreach ($carrier->preview($companyId, $orders->values(), []) as $row) {
            $preview[(int) $row['order_id']] = $row;
        }

        $rows = [];
        foreach ($ids as $id) {
            $order = $orders->get($id);
            if (! $order) {
                $rows[] = $this->missingRow((int) $id);

                continue;
            }
            $row = $preview[(int) $id] ?? null;
            $errors = array_values($row['errors'] ?? []);
            $existing = $this->carriers->shipmentFor($order);
            if ($existing && $existing['carrier'] !== $carrier->key()) {
                $errors[] = $this->alreadySent($existing);
            } elseif ($existing && $existing['carrier'] === $carrier->key() && $errors === []) {
                $errors[] = $this->alreadySent($existing);
            } elseif (! empty($row['already']) && $errors === []) {
                $tracking = $row['already']['tracking_number'] ?? $row['already']['tracking'] ?? null;
                $errors[] = 'Déjà envoyée à '.$carrier->label().($tracking ? ' (n° '.$tracking.').' : '.');
            }
            if ($blocker = $order->carrierShipBlocker()) {
                $errors[] = $blocker;
            }
            $errors = array_values(array_unique($errors));
            $rows[] = $this->pack($order, $errors);
        }

        return $rows;
    }

    protected function genericRow(CarrierInterface $carrier, Order $order): array
    {
        $errors = [];
        $existing = $this->carriers->shipmentFor($order);
        if ($existing) {
            $errors[] = $this->alreadySent($existing);
        }
        if ($blocker = $order->carrierShipBlocker()) {
            $errors[] = $blocker;
        }
        if (! $order->isConfirmed()) {
            $errors[] = 'La commande doit être confirmée avant l’envoi.';
        }
        if (in_array($order->deliveryCategory(), ['succes', 'annulation', 'retour'], true)) {
            $errors[] = 'Commande au statut « '.$order->deliveryStatusLabel().' » : envoi impossible.';
        }
        $address = is_array($order->shipping_address) ? $order->shipping_address : [];
        $name = trim((string) ($order->customer_name ?: trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')) ?: ($address['name'] ?? '')));
        $phone = SpeedafShipmentService::normalizePhone($order->phone ?: ($address['phone'] ?? null));
        $city = trim((string) $order->shippingCity());
        $street = trim((string) $order->shippingAddressLine());
        if ($name === '') {
            $errors[] = 'Nom du client manquant.';
        }
        if ($phone === '') {
            $errors[] = 'Téléphone manquant.';
        }
        if ($city === '') {
            $errors[] = 'Ville manquante.';
        }
        if ($street === '') {
            $errors[] = 'Adresse de livraison manquante.';
        }

        return $this->pack($order, $errors);
    }

    /** @param  array{carrier_label?:string,tracking?:?string}  $existing */
    protected function alreadySent(array $existing): string
    {
        $label = $existing['carrier_label'] ?? 'un autre transporteur';
        $tracking = $existing['tracking'] ?? null;

        return 'Déjà envoyée à '.$label.($tracking ? ' (n° '.$tracking.').' : '.');
    }

    /** @param  list<string>  $errors */
    protected function pack(Order $order, array $errors): array
    {
        return [
            'order_id' => $order->id,
            'reference' => $order->reference(),
            'amount_due' => round($order->amountDue(), 2),
            'can_send' => $errors === [],
            'errors' => $errors,
            'reason' => $errors[0] ?? null,
        ];
    }

    protected function missingRow(int $id): array
    {
        return [
            'order_id' => $id,
            'reference' => '#'.$id,
            'amount_due' => 0.0,
            'can_send' => false,
            'errors' => ['Commande introuvable.'],
            'reason' => 'Commande introuvable.',
        ];
    }
}

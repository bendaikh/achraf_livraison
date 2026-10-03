<?php

namespace App\Services\Carriers;

use App\Models\Order;
use App\Models\User;

/**
 * An external delivery company (« société de livraison ») that can receive parcels from the
 * Commandes page. Speedaf implements it today; Sift, Ozon… plug in by implementing this
 * interface and listing their class in config/carriers.php — the quick-ship popup, bulk bar,
 * filters and order payload pick them up automatically.
 */
interface CarrierInterface
{
    /** Stable key used in URLs / filters (e.g. "speedaf"). */
    public function key(): string;

    /** Display name (e.g. "Speedaf"). */
    public function label(): string;

    /** Brand colour for the logo chip (#hex). */
    public function color(): string;

    /** Null when the carrier can ship for this company, otherwise a French reason (config missing, disabled…). */
    public function unavailableReason(int $companyId): ?string;

    /**
     * Create parcels. Never throws: one result per order.
     *
     * @param  iterable<Order>  $orders
     * @return list<array{order_id:int, reference:string, success:bool, tracking:?string, message:string}>
     */
    public function ship(int $companyId, iterable $orders, ?User $user = null): array;

    /** Current (non-cancelled) shipment of the order with this carrier, normalised; null if none. */
    public function shipmentFor(Order $order): ?array;

    /**
     * Label links for the given orders (only those shipped with this carrier).
     *
     * @param  iterable<Order>  $orders
     * @return list<array{order_id:int, reference:string, tracking:string, pdf_url:?string}>
     *
     * @throws CarrierException
     */
    public function labels(int $companyId, iterable $orders): array;

    /** SQL restriction "order has an active shipment with this carrier" (filters). */
    public function scopeShipped(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder;
}

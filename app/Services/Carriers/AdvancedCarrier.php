<?php

namespace App\Services\Carriers;

use App\Models\Order;
use App\Models\User;

/**
 * Optional extras a carrier can offer on top of CarrierInterface (Ozon Express): a validation
 * preview before sending, per-send options (ouverture, fragile…) and a capability list read by
 * the UI (delivery notes, bulk gate…).
 */
interface AdvancedCarrier
{
    /** @return array{preview:bool, delivery_notes:bool, bulk_enabled:bool, bulk_reason:?string} */
    public function capabilities(int $companyId): array;

    /** @param iterable<Order> $orders */
    public function preview(int $companyId, iterable $orders, array $options = []): array;

    /** Same contract as ship(), with options chosen in the validation popup. */
    public function shipWithOptions(int $companyId, iterable $orders, ?User $user, array $options): array;
}

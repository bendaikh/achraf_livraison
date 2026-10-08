<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * Single rule for the amount still to collect.
 * Shopify: total_outstanding when known, otherwise max(0, total − amount_paid).
 * A Shopify order still marked items_edited_at is a legacy local total: collect
 * max(0, total − amount_paid) so the COD follows what the team confirmed.
 * Manual: paid → 0, cash on delivery → total, partial → total − amount_paid.
 */
class AmountDue
{
    public function calculate(Order $order): float
    {
        $total = round((float) $order->total_price, 2);
        $paid = round((float) ($order->amount_paid ?? 0), 2);

        if ($order->shopify_order_id) {
            if ($order->items_edited_at) {
                return round(max(0, $total - $paid), 2);
            }
            if ($order->total_outstanding !== null && $order->total_outstanding !== '') {
                return round(max(0, (float) $order->total_outstanding), 2);
            }

            return round(max(0, $total - $paid), 2);
        }

        if ($order->financial_status === 'paid') {
            return 0.0;
        }
        if ($paid > 0 && $paid < $total) {
            return round(max(0, $total - $paid), 2);
        }

        return round(max(0, $total - $paid), 2);
    }

    public function apply(Order $order): void
    {
        $order->amount_due = $this->calculate($order);
    }
}

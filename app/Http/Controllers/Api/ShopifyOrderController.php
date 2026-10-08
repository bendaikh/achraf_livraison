<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Shopify\ShopifyFulfillmentService;
use App\Services\Shopify\ShopifyOrderEditService;
use Illuminate\Http\Request;

/** Outbound Shopify actions started by a user: customer fields and tracking. */
class ShopifyOrderController extends Controller
{
    public function updateCustomer(Request $request, Order $order, ShopifyOrderEditService $editor)
    {
        abort_unless($request->user()->can('orders.edit_shopify_customer'), 403);
        $this->sameCompany($request, $order);
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:5000'],
            'shipping_address' => ['nullable', 'array'],
            'shipping_address.address1' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:100'],
            'shipping_address.phone' => ['nullable', 'string', 'max:40'],
        ]);
        $updated = $editor->updateCustomer($order, $data, $request->user());

        return new OrderResource($updated->load(['shop:id,shop_domain,shop_name', 'fulfillments']));
    }

    public function sendTracking(Request $request, Order $order, ShopifyFulfillmentService $fulfillments)
    {
        abort_unless($request->user()->can('orders.ship'), 403);
        $this->sameCompany($request, $order);
        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:80'],
            'tracking_company' => ['nullable', 'string', 'max:80'],
            'tracking_url' => ['nullable', 'string', 'max:500'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);
        $row = $fulfillments->create(
            $order,
            $data['tracking_number'],
            $data['tracking_company'] ?? null,
            $data['tracking_url'] ?? null,
            (bool) ($data['notify_customer'] ?? false),
            $request->user(),
        );

        return response()->json([
            'message' => 'Suivi envoyé à Shopify.',
            'fulfillment' => [
                'id' => $row->id,
                'tracking_number' => $row->tracking_number,
                'tracking_company' => $row->tracking_company,
                'tracking_url' => $row->tracking_url,
                'status' => $row->status,
            ],
        ]);
    }

    public function takeRemote(Request $request, Order $order, ShopifyOrderEditService $editor)
    {
        abort_unless($request->user()->can('orders.edit_items'), 403);
        $this->sameCompany($request, $order);
        $updated = $editor->takeRemote($order, $request->user());

        return new OrderResource($updated->load(['shop:id,shop_domain,shop_name', 'fulfillments', 'histories.user']));
    }

    public function retry(Request $request, Order $order)
    {
        abort_unless($request->user()->can('orders.edit_items'), 403);
        $this->sameCompany($request, $order);
        $log = \App\Models\ShopifySyncLog::query()
            ->where('company_id', $order->company_id)
            ->where('entity_id', $order->id)
            ->whereIn('entity_type', ['order', 'fulfillment'])
            ->where('direction', 'out')
            ->where('status', 'failed')
            ->latest('id')
            ->first();
        if (! $log) {
            return response()->json(['message' => 'Aucun échec à relancer.'], 422);
        }
        $log->forceFill(['status' => 'pending', 'error' => null])->save();
        $order->forceFill(['shopify_sync_status' => 'pending', 'shopify_sync_error' => null])->save();
        \App\Jobs\RetryShopifyOutboundJob::dispatch($log->id)->onQueue('shopify');

        return response()->json(['message' => 'Nouvel essai programmé.']);
    }

    protected function sameCompany(Request $request, Order $order): void
    {
        abort_unless((int) $order->company_id === (int) $request->user()->resolveCompanyId(), 404);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ConfirmationStatus;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));

        $query = Order::query()
            ->with(['shop:id,shop_domain,shop_name'])
            ->latest('shopify_created_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage);

        $orders = collect($paginator->items())->map(fn (Order $order) => [
            'id' => $order->id,
            'name' => $order->name,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'email' => $order->email,
            'phone' => $order->phone,
            'city' => data_get($order->shipping_address, 'city'),
            'status' => $order->status,
            'confirmation_status' => $order->confirmation_status,
            'confirmation_status_label' => Order::confirmationLabel((string) $order->confirmation_status),
            'confirmation_status_color' => ConfirmationStatus::colorFor($order->confirmation_status),
            'financial_status' => $order->financial_status,
            'fulfillment_status' => $order->fulfillment_status,
            'total_price' => $order->total_price,
            'currency' => $order->currency,
            'line_items_count' => is_array($order->line_items) ? count($order->line_items) : 0,
            'shop_name' => $order->shop?->shop_name,
            'shopify_created_at' => $order->shopify_created_at?->toIso8601String(),
        ]);

        return response()->json([
            'orders' => $orders,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}

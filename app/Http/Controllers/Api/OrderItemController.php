<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Catalog\OrderItemEditor;
use Illuminate\Http\Request;

/**
 * Order lines edition (fiche commande + Confirmation). Internal Lav'Fast Flow changes only —
 * never pushed to Shopify automatically. Requires orders.edit_items; changing a price
 * requires orders.edit_prices.
 */
class OrderItemController extends Controller
{
    public function __construct(protected OrderItemEditor $editor) {}

    public function store(Request $request, Order $order)
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $variant = $this->variant($request, $data['variant_id']);
        $price = $this->price($request, $data);

        return $this->respond($this->editor->add($order, $variant, $data['quantity'], $price, $request->user()));
    }

    public function update(Request $request, Order $order, string $key)
    {
        $data = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $result = null;
        if (isset($data['quantity'])) {
            $result = $this->editor->updateQuantity($order, $key, (int) $data['quantity'], $request->user());
        }
        if (array_key_exists('price', $data) && $data['price'] !== null) {
            abort_unless($request->user()->can('orders.edit_prices'), 403, 'Vous n’avez pas le droit de modifier les prix.');
            $warning = $result['warning'] ?? null;
            $result = $this->editor->updatePrice($result['order'] ?? $order, $key, (float) $data['price'], $request->user());
            $result['warning'] ??= $warning;
        }
        abort_if($result === null, 422, 'Rien à modifier.');

        return $this->respond($result);
    }

    public function destroy(Request $request, Order $order, string $key)
    {
        return $this->respond($this->editor->remove($order, $key, $request->user()));
    }

    public function replace(Request $request, Order $order, string $key)
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $variant = $this->variant($request, $data['variant_id']);

        return $this->respond($this->editor->replace($order, $key, $variant, $data['quantity'], $this->price($request, $data), $request->user()));
    }

    protected function variant(Request $request, int $id): ProductVariant
    {
        // Company isolation: only variants of the user's company catalog.
        return ProductVariant::query()->forCompany($request->user()->resolveCompanyId())->with('product.shop')->findOrFail($id);
    }

    protected function price(Request $request, array $data): ?float
    {
        if (! isset($data['price']) || $data['price'] === null || $data['price'] === '') {
            return null; // default = current Shopify price
        }
        abort_unless($request->user()->can('orders.edit_prices'), 403, 'Vous n’avez pas le droit de modifier les prix.');

        return (float) $data['price'];
    }

    protected function respond(array $result)
    {
        $order = $result['order']->fresh()->load(['deliveryStatus', 'driver', 'assignedUser', 'assignedByUser:id,name', 'shop:id,shop_domain,shop_name', 'missions.driver', 'histories.user', 'speedafShipments', 'ozonShipments.deliveryNote', 'siftShipments']);

        return (new OrderResource($order))->additional([
            'warning' => $result['warning'] ?? null,
            'changed' => $result['changed'] ?? true,
        ]);
    }
}

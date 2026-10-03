<?php

namespace App\Services\Catalog;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Internal edition of the order lines (fiche commande + Confirmation): add / quantity / price /
 * remove / replace. Every change is historised (order_status_histories kind "produits" + timeline)
 * with the user and date/time, and the total is recalculated by the difference of the lines
 * subtotal (shipping, discounts and taxes already in the total are kept).
 *
 * These are Lav'Fast Flow changes only: nothing is pushed to Shopify (each history row carries
 * scope=internal, shopify=not_pushed so a future "push to Shopify" can find them). After an
 * internal edit, Shopify order updates no longer overwrite the lines (see OrderSyncService).
 */
class OrderItemEditor
{
    public function add(Order $order, ProductVariant $variant, int $quantity, ?float $price, User $user): array
    {
        $warning = $this->checkStock($variant, $quantity);

        return $this->mutate($order, $user, function (array $lines) use ($variant, $quantity, $price) {
            $line = $this->lineFromVariant($variant, $quantity, $price);
            $lines[] = $line;

            return [$lines, 'Produit ajouté : '.OrderLines::label($line).' × '.$quantity, ['action' => 'add', 'to' => $this->snap($line)]];
        }, $warning);
    }

    public function updateQuantity(Order $order, string $key, int $quantity, User $user): array
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'La quantité doit être au moins 1 (utilisez « Supprimer de la commande »).']);
        }
        $current = $this->find($order, $key);
        $warning = null;
        if ($quantity > (int) $current['quantity'] && ($variant = app(CatalogLookup::class)->variantFor($current))) {
            $warning = $this->checkStock($variant, $quantity);
        }

        return $this->mutate($order, $user, function (array $lines) use ($key, $quantity) {
            $i = $this->indexOf($lines, $key);
            $old = (int) $lines[$i]['quantity'];
            if ($old === $quantity) {
                return null;
            }
            $lines[$i]['quantity'] = $quantity;

            return [$lines, 'Quantité '.$old.' → '.$quantity.' : '.OrderLines::label($lines[$i]), ['action' => 'quantity', 'from' => $old, 'to' => $quantity, 'line' => $this->snap($lines[$i])]];
        }, $warning);
    }

    public function updatePrice(Order $order, string $key, float $price, User $user): array
    {
        if ($price < 0) {
            throw ValidationException::withMessages(['price' => 'Prix invalide.']);
        }

        return $this->mutate($order, $user, function (array $lines) use ($key, $price) {
            $i = $this->indexOf($lines, $key);
            $old = (float) ($lines[$i]['price'] ?? 0);
            if (abs($old - $price) < 0.005) {
                return null;
            }
            $lines[$i]['price'] = number_format($price, 2, '.', '');
            $lines[$i]['price_overridden'] = true;

            return [$lines, 'Prix '.$this->dh($old).' → '.$this->dh($price).' : '.OrderLines::label($lines[$i]), ['action' => 'price', 'from' => $old, 'to' => $price, 'line' => $this->snap($lines[$i])]];
        });
    }

    public function remove(Order $order, string $key, User $user): array
    {
        return $this->mutate($order, $user, function (array $lines) use ($key) {
            $i = $this->indexOf($lines, $key);
            $removed = $lines[$i];
            array_splice($lines, $i, 1);
            $label = 'Produit supprimé : '.OrderLines::label($removed).($lines === [] ? ' (commande vide)' : '');

            return [$lines, $label, ['action' => 'remove', 'from' => $this->snap($removed)]];
        });
    }

    public function replace(Order $order, string $key, ProductVariant $variant, int $quantity, ?float $price, User $user): array
    {
        $warning = $this->checkStock($variant, $quantity);

        return $this->mutate($order, $user, function (array $lines) use ($key, $variant, $quantity, $price) {
            $i = $this->indexOf($lines, $key);
            $old = $lines[$i];
            $new = $this->lineFromVariant($variant, $quantity, $price);
            $lines[$i] = $new;

            return [$lines, OrderLines::label($old).' remplacé par '.OrderLines::label($new).' × '.$quantity, ['action' => 'replace', 'from' => $this->snap($old), 'to' => $this->snap($new)]];
        }, $warning);
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * @param  callable(array): (array{0: array, 1: string, 2: array}|null)  $change
     * @return array{order: Order, warning: ?string, changed: bool}
     */
    protected function mutate(Order $order, User $user, callable $change, ?string $warning = null): array
    {
        return DB::transaction(function () use ($order, $user, $change, $warning) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $before = OrderLines::normalize($locked->line_items);
            $result = $change($before);
            if ($result === null) {
                return ['order' => $locked, 'warning' => $warning, 'changed' => false];
            }
            [$after, $label, $data] = $result;

            $oldSubtotal = OrderLines::subtotal($before);
            $newSubtotal = OrderLines::subtotal($after);
            $oldTotal = (float) $locked->total_price;
            $newTotal = max(0, round($oldTotal + ($newSubtotal - $oldSubtotal), 2));

            if (! $locked->items_edited_at && $locked->shopify_order_id && $locked->shopify_line_items === null) {
                $locked->shopify_line_items = $before; // original Shopify lines, kept for reference
            }
            $locked->line_items = array_values($after);
            $locked->total_price = $newTotal;
            $locked->items_edited_at = now();
            $locked->items_edited_by = $user->id;
            $locked->appendHistory('items_changed', $label, $user, ['action' => $data['action']]);
            $locked->save();

            OrderStatusHistory::create([
                'order_id' => $locked->id,
                'kind' => 'produits',
                'status_code' => 'items_'.$data['action'],
                'status_name' => match ($data['action']) {
                    'add' => 'Produit ajouté',
                    'quantity' => 'Quantité modifiée',
                    'price' => 'Prix modifié',
                    'remove' => 'Produit supprimé',
                    'replace' => 'Produit remplacé',
                    default => 'Produits modifiés',
                },
                'status_color' => '#7c3aed',
                'data' => $data + [
                    'total_from' => $oldTotal,
                    'total_to' => $newTotal,
                    'scope' => 'internal',
                    'shopify' => 'not_pushed',
                ],
                'note' => $label.($oldTotal !== $newTotal ? ' · Total '.$this->dh($oldTotal).' → '.$this->dh($newTotal) : ''),
                'user_id' => $user->id,
            ]);

            return ['order' => $locked, 'warning' => $warning, 'changed' => true];
        });
    }

    /** Warns when out of stock; blocks only if the company forbids it and Shopify does not allow overselling. */
    protected function checkStock(ProductVariant $variant, int $quantity): ?string
    {
        if (! $variant->inventory_tracked) {
            return null;
        }
        $stock = (int) $variant->inventory_quantity;
        if ($stock >= $quantity) {
            return null;
        }
        $message = $stock <= 0
            ? 'Rupture de stock pour '.$variant->product?->title.'.'
            : 'Stock insuffisant : '.$stock.' en stock pour '.$quantity.' demandé(s).';
        if (! (bool) Setting::getValue('allow_out_of_stock_items', true) && ! $variant->allowsOversell()) {
            throw ValidationException::withMessages(['variant_id' => $message.' Les précommandes ne sont pas autorisées (Paramètres).']);
        }

        return $message;
    }

    protected function lineFromVariant(ProductVariant $variant, int $quantity, ?float $price): array
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'La quantité doit être au moins 1.']);
        }
        $product = $variant->product;

        return array_filter([
            'key' => 'l'.Str::lower(Str::random(10)),
            'id' => null, // not a Shopify line (added in Lav'Fast Flow)
            'title' => $product?->title ?? 'Produit',
            'variant_title' => $variant->displayTitle(),
            'quantity' => $quantity,
            'sku' => $variant->sku,
            'price' => number_format($price ?? (float) $variant->price, 2, '.', ''),
            'price_overridden' => $price !== null && abs($price - (float) $variant->price) >= 0.005 ? true : null,
            'product_id' => $product?->shopify_product_id,
            'variant_id' => $variant->shopify_variant_id,
            'catalog_variant_id' => $variant->id,
            'image' => $variant->imageUrl(),
            'source' => 'lavfast',
        ], fn ($v) => $v !== null);
    }

    protected function find(Order $order, string $key): array
    {
        $lines = OrderLines::normalize($order->line_items);

        return $lines[$this->indexOf($lines, $key)];
    }

    protected function indexOf(array $lines, string $key): int
    {
        foreach ($lines as $i => $line) {
            if ((string) $line['key'] === $key) {
                return $i;
            }
        }
        throw ValidationException::withMessages(['line' => 'Ligne de commande introuvable (la commande a peut-être été modifiée entre-temps, rechargez la page).']);
    }

    protected function snap(array $line): array
    {
        return array_intersect_key($line, array_flip(['title', 'variant_title', 'sku', 'quantity', 'price', 'variant_id', 'catalog_variant_id']));
    }

    protected function dh(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',').' DH';
    }
}

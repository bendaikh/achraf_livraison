<?php

namespace App\Services\Sav;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\SavRequest;
use App\Models\SavStatusHistory;
use App\Models\User;
use App\Services\Catalog\CatalogLookup;
use App\Services\Catalog\OrderLines;
use App\Services\MissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * T7 — Retours / échanges. Each request keeps a snapshot of the original order, its own timeline,
 * item custody (what the driver must deliver / has picked up / must bring back) and a linked
 * Mission (type retour|echange) so the driver fee is the driver's tariff snapshotted at assignment
 * and flows into the existing caisse / clôture.
 */
class SavService
{
    public function __construct(private MissionService $missions, private CatalogLookup $catalog) {}

    public function isDelivered(Order $order): bool
    {
        return $order->deliveryStatusDefinition()?->category === 'succes';
    }

    /** Auto-fill data from the original order. */
    public function prefill(Order $order): array
    {
        $order->loadMissing('driver');

        return [
            'order_id' => $order->id,
            'reference' => $order->reference(),
            'customer_name' => $order->customer_name,
            'phone' => $order->phone,
            'address' => $order->shippingAddressLine(),
            'city' => $order->shippingCity(),
            'amount_paid' => (float) $order->total_price,
            'payment_method' => $order->paymentMethod(),
            'original_driver' => $order->driver ? ['id' => $order->driver->id, 'name' => $order->driver->name] : null,
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'delivered' => $this->isDelivered($order),
            'lines' => array_map(fn ($l) => [
                'key' => $l['key'], 'title' => $l['title'] ?? $l['name'] ?? 'Produit', 'variant_title' => $l['variant_title'] ?? null,
                'sku' => $l['sku'] ?? null, 'quantity' => (int) $l['quantity'], 'price' => (float) ($l['price'] ?? 0), 'image_url' => $l['image_url'] ?? null,
            ], array_values(array_filter($this->catalog->enrichOrder($order), fn ($l) => (int) $l['quantity'] > 0))),
            'open_requests' => SavRequest::query()->where('order_id', $order->id)->whereNotIn('status', ['closed', 'cancelled'])->count(),
        ];
    }

    public function create(Order $order, array $data, User $user): SavRequest
    {
        if (! $this->isDelivered($order)) {
            throw ValidationException::withMessages(['order_id' => 'Seules les commandes livrées peuvent faire l’objet d’un retour ou d’un échange.']);
        }
        $lines = collect($this->catalog->enrichOrder($order))->keyBy('key');

        return DB::transaction(function () use ($order, $data, $user, $lines) {
            $s = SavRequest::create([
                'company_id' => $user->resolveCompanyId(),
                'order_id' => $order->id,
                'type' => $data['type'],
                'status' => 'created',
                'reason' => $data['reason'],
                'comment' => $data['comment'] ?? null,
                'sav_note' => $data['sav_note'] ?? null,
                'customer_name' => $order->customer_name,
                'phone' => $order->phone,
                'address' => $order->shippingAddressLine(),
                'city' => $order->shippingCity(),
                'amount_paid' => $order->total_price,
                'original_driver_id' => $order->driver_id,
                'original_delivered_at' => $order->delivered_at,
                'created_by' => $user->id,
            ]);

            foreach ($data['pickup'] as $p) {
                $line = $lines->get($p['key']);
                if (! $line) {
                    throw ValidationException::withMessages(['pickup' => 'Produit introuvable dans la commande d’origine.']);
                }
                if ((int) $p['quantity'] > (int) $line['quantity']) {
                    throw ValidationException::withMessages(['pickup' => 'Quantité supérieure à la quantité commandée pour « '.OrderLines::label($line).' ».']);
                }
                $variant = $this->catalog->variantFor($line);
                $s->items()->create([
                    'direction' => 'pickup', 'line_key' => $line['key'], 'product_variant_id' => $variant?->id,
                    'title' => $line['title'] ?? $line['name'] ?? 'Produit', 'variant_title' => $line['variant_title'] ?? null,
                    'sku' => $line['sku'] ?? null, 'image_url' => $line['image_url'] ?? null,
                    'quantity' => (int) $p['quantity'], 'unit_price' => $line['price'] ?? null,
                ]);
            }
            foreach ($data['deliver'] ?? [] as $d) {
                $variant = ProductVariant::query()->forCompany($user->resolveCompanyId())->with('product')->findOrFail($d['variant_id']);
                $s->items()->create([
                    'direction' => 'deliver', 'product_variant_id' => $variant->id,
                    'title' => $variant->product?->title ?? 'Produit', 'variant_title' => $variant->displayTitle(),
                    'sku' => $variant->sku, 'image_url' => $variant->imageUrl(),
                    'quantity' => (int) $d['quantity'], 'unit_price' => $variant->price,
                ]);
            }

            $type = config("sav.types.{$s->type}.label");
            $this->log($s, null, 'created', 'Demande créée', trim($s->reason.($s->comment ? ' — '.$s->comment : '')), $user);
            OrderStatusHistory::create([
                'order_id' => $order->id, 'kind' => 'sav', 'status_code' => 'sav_'.$s->type, 'status_name' => "{$type} {$s->reference}",
                'status_color' => '#7c3aed', 'note' => "{$type} créé : {$s->reason}", 'user_id' => $user->id, 'data' => ['sav_request_id' => $s->id],
            ]);
            $this->setStatus($s, 'to_assign', 'À attribuer', null, $user);

            if (! empty($data['driver_id'])) {
                $this->assign($s, (int) $data['driver_id'], $user);
            }

            return $s->fresh(['items']);
        });
    }

    public function assign(SavRequest $s, int $driverId, User $user): SavRequest
    {
        if (! in_array($s->status, ['created', 'to_assign', 'assigned', 'no_answer', 'postponed', 'problem'], true)) {
            throw ValidationException::withMessages(['driver_id' => 'Le livreur ne peut plus être changé à cette étape ('.SavRequest::statusLabel($s->status).').']);
        }
        $driver = Driver::query()->where('is_active', true)->find($driverId);
        if (! $driver) {
            throw ValidationException::withMessages(['driver_id' => 'Livreur introuvable ou inactif.']);
        }
        if ((int) $s->driver_id === $driver->id) {
            return $s;
        }

        return DB::transaction(function () use ($s, $driver, $user) {
            $from = $s->driver?->name;
            if ($s->mission) {
                $this->missions->assign($s->mission, $driver->id);
            } else {
                $mission = $this->missions->create([
                    'type' => $s->type, 'order_id' => $s->order_id, 'driver_id' => $driver->id,
                    'contact_name' => $s->customer_name, 'phone' => $s->phone, 'address' => $s->address, 'city' => $s->city,
                    'items_description' => $s->items()->get()->map(fn ($i) => ($i->direction === 'pickup' ? 'Récupérer : ' : 'Remettre : ').$i->quantity.' × '.$i->title.($i->variant_title ? " ({$i->variant_title})" : ''))->implode(' | '),
                    'quantity' => max(1, (int) $s->items()->sum('quantity')),
                    'scheduled_date' => now()->toDateString(),
                    'note' => trim('SAV '.$s->reference.' — '.$s->reason.($s->sav_note ? ' — '.$s->sav_note : '')),
                ]);
                $s->mission_id = $mission->id;
            }
            $mission = $s->mission()->first();
            $s->driver_id = $driver->id;
            $s->driver_fee = $mission?->driver_price;
            $s->assigned_at = now();
            $s->save();
            // New product entrusted to the driver.
            $s->items()->where('direction', 'deliver')->whereIn('state', ['pending', 'with_driver'])->update(['state' => 'with_driver']);

            $label = $from ? "Réaffectée de {$from} à {$driver->name}" : "Attribuée à {$driver->name}";
            $fee = number_format((float) $s->driver_fee, 2, ',', ' ').' DH';
            $this->log($s, $s->status, 'assigned', $label, "Frais livreur : {$fee}", $user, null, 'assign');
            if ($s->status !== 'assigned') {
                $this->setStatus($s, 'assigned', 'Attribuée', null, $user);
            }

            return $s->fresh();
        });
    }

    /** Allowed actions for an actor on a request. */
    public function allowedActions(SavRequest $s, string $actor): array
    {
        $out = [];
        foreach (config('sav.actions') as $key => $a) {
            if (! in_array($actor, $a['by'], true) || ! in_array($s->status, $a['from'], true)) {
                continue;
            }
            if (isset($a['types']) && ! in_array($s->type, $a['types'], true)) {
                continue;
            }
            if ($actor === 'driver' && ! $s->driver_id) {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $a['label'], 'to' => $a['to']];
        }

        return $out;
    }

    public function act(SavRequest $s, string $action, string $actor, ?User $user, array $input = [], ?Driver $driver = null): SavRequest
    {
        $def = config("sav.actions.$action");
        if (! $def || ! collect($this->allowedActions($s, $actor))->contains('key', $action)) {
            throw ValidationException::withMessages(['action' => 'Action impossible à l’étape « '.SavRequest::statusLabel($s->status).' ».']);
        }
        $comment = trim((string) ($input['comment'] ?? '')) ?: null;
        if ($action === 'problem' && ! $comment) {
            throw ValidationException::withMessages(['comment' => 'Décrivez le problème.']);
        }
        if ($action === 'postpone') {
            if (empty($input['postponed_until'])) {
                throw ValidationException::withMessages(['postponed_until' => 'Choisissez la date du report.']);
            }
            $s->postponed_until = Carbon::parse($input['postponed_until']);
            $comment = trim('Reportée au '.$s->postponed_until->format('d/m/Y H:i').($comment ? ' — '.$comment : ''));
        }

        return DB::transaction(function () use ($s, $action, $def, $user, $driver, $comment) {
            $now = now();
            switch ($action) {
                case 'en_route':
                case 'at_customer':
                    $this->missionStatus($s, 'en_cours');
                    break;
                case 'picked_up':
                    $s->picked_up_at = $now;
                    $s->items()->where('direction', 'pickup')->update(['state' => 'with_driver']);
                    $this->missionStatus($s, 'terminee', $comment);
                    break;
                case 'exchanged':
                    $s->picked_up_at = $now;
                    $s->new_delivered_at = $now;
                    $s->items()->where('direction', 'pickup')->update(['state' => 'with_driver']);
                    $s->items()->where('direction', 'deliver')->update(['state' => 'delivered']);
                    $this->missionStatus($s, 'terminee', $comment);
                    break;
                case 'receive':
                    $s->received_at = $now;
                    $s->received_by = $user?->id;
                    $s->items()->where('direction', 'pickup')->update(['state' => 'at_depot']);
                    break;
                case 'close':
                    $s->closed_at = $now;
                    $s->closed_by = $user?->id;
                    break;
                case 'cancel':
                    $s->items()->where('direction', 'deliver')->where('state', 'with_driver')->update(['state' => 'returned']);
                    if ($s->mission && $s->mission->status !== 'terminee') {
                        $this->missionStatus($s, 'annulee', $comment);
                    }
                    break;
            }
            $s->save();
            $this->setStatus($s, $def['to'], SavRequest::statusLabel($def['to']), $comment, $user, $driver);
            if (in_array($action, ['close', 'cancel'], true)) {
                OrderStatusHistory::create([
                    'order_id' => $s->order_id, 'kind' => 'sav', 'status_code' => 'sav_'.$def['to'],
                    'status_name' => config("sav.types.{$s->type}.label")." {$s->reference} — ".SavRequest::statusLabel($def['to']),
                    'status_color' => SavRequest::statusColor($def['to']), 'note' => $comment, 'user_id' => $user?->id, 'data' => ['sav_request_id' => $s->id],
                ]);
            }

            return $s->fresh();
        });
    }

    private function missionStatus(SavRequest $s, string $status, ?string $note = null): void
    {
        $mission = $s->mission()->first();
        if ($mission && ! $mission->closing_id && $mission->status !== $status && $mission->status !== 'terminee') {
            $this->missions->changeStatus($mission, $status, $note ?? 'SAV '.$s->reference);
        }
    }

    private function setStatus(SavRequest $s, string $to, string $label, ?string $comment, ?User $user, ?Driver $driver = null): void
    {
        $from = $s->status;
        $s->status = $to;
        $s->save();
        $this->log($s, $from, $to, $label, $comment, $user, $driver);
    }

    private function log(SavRequest $s, ?string $from, ?string $to, string $label, ?string $comment, ?User $user, ?Driver $driver = null, string $event = 'status'): void
    {
        SavStatusHistory::create([
            'sav_request_id' => $s->id, 'from_status' => $from, 'to_status' => $to, 'event' => $event,
            'label' => $label, 'comment' => $comment, 'user_id' => $user?->id, 'driver_id' => $driver?->id,
        ]);
    }

    public function payload(SavRequest $s, string $actor = 'admin', bool $full = false): array
    {
        $s->loadMissing(['items', 'driver:id,name,phone', 'originalDriver:id,name', 'order:id,order_number,name,source,shopify_order_id']);
        $out = [
            'id' => $s->id,
            'reference' => $s->reference,
            'type' => $s->type,
            'type_label' => config("sav.types.{$s->type}.label"),
            'status' => $s->status,
            'status_label' => SavRequest::statusLabel($s->status),
            'status_color' => SavRequest::statusColor($s->status),
            'reason' => $s->reason,
            'comment' => $s->comment,
            'sav_note' => $s->sav_note,
            'order_id' => $s->order_id,
            'order_reference' => $s->order?->reference(),
            'customer_name' => $s->customer_name,
            'phone' => $s->phone,
            'address' => $s->address,
            'city' => $s->city,
            'amount_paid' => $s->amount_paid !== null ? (float) $s->amount_paid : null,
            'original_driver_name' => $s->originalDriver?->name,
            'original_delivered_at' => $s->original_delivered_at?->toIso8601String(),
            'driver_id' => $s->driver_id,
            'driver_name' => $s->driver?->name,
            'driver_phone' => $s->driver?->phone,
            'driver_fee' => $s->driver_fee !== null ? (float) $s->driver_fee : null,
            'postponed_until' => $s->postponed_until?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
            'assigned_at' => $s->assigned_at?->toIso8601String(),
            'picked_up_at' => $s->picked_up_at?->toIso8601String(),
            'new_delivered_at' => $s->new_delivered_at?->toIso8601String(),
            'received_at' => $s->received_at?->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(),
            'pickup' => $s->items->where('direction', 'pickup')->map->toPayload()->values(),
            'deliver' => $s->items->where('direction', 'deliver')->map->toPayload()->values(),
            'actions' => $this->allowedActions($s, $actor),
            'can_assign' => in_array($s->status, ['created', 'to_assign', 'assigned', 'no_answer', 'postponed', 'problem'], true),
        ];
        if ($full) {
            $out['history'] = $s->histories()->with('user:id,name', 'driver:id,name')->get()->map(fn ($h) => [
                'id' => $h->id, 'event' => $h->event, 'label' => $h->label, 'comment' => $h->comment,
                'from_label' => $h->from_status ? SavRequest::statusLabel($h->from_status) : null,
                'to_label' => $h->to_status ? SavRequest::statusLabel($h->to_status) : null,
                'to_color' => SavRequest::statusColor($h->to_status),
                'by' => $h->driver?->name ? $h->driver->name.' (livreur)' : $h->user?->name,
                'created_at' => $h->created_at?->toIso8601String(),
            ])->values();
        }

        return $out;
    }
}

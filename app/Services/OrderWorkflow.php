<?php

namespace App\Services;

use App\Models\DeliveryStatus;
use App\Models\Mission;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Setting;
use App\Models\StatusTransition;
use App\Support\Catalog;
use App\Support\CurrentUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderWorkflow
{
    /** Livraison mission status derived from the order's status category. */
    public const MISSION_STATUS_BY_CATEGORY = [
        'avant_livraison' => 'a_faire',
        'report' => 'a_faire',
        'injoignable' => 'a_faire',
        'en_livraison' => 'en_cours',
        'succes' => 'terminee',
        'echec' => 'echouee',
        'retour' => 'echouee',
        'annulation' => 'annulee',
    ];

    public function __construct(protected MissionService $missions) {}

    /** Status automatically applied when an order gets confirmed (configurable). */
    public function statusOnConfirm(): ?DeliveryStatus
    {
        $id = Setting::getValue('status_on_confirm_id');

        return ($id ? DeliveryStatus::active()->find($id) : null)
            ?? DeliveryStatus::active()->where('category', 'avant_livraison')->ordered()->first();
    }

    /** Status automatically applied when a driver is assigned (configurable). */
    public function statusOnAssign(): ?DeliveryStatus
    {
        $id = Setting::getValue('status_on_assign_id');

        return $id ? DeliveryStatus::active()->find($id) : null;
    }

    public function transitionsEnforced(): bool
    {
        return (bool) Setting::getValue('enforce_status_transitions', false);
    }

    /**
     * Ids of statuses reachable from the order's current status, or null when every
     * active status is allowed (enforcement off, or no transition defined for the current status).
     */
    public function allowedStatusIds(Order $order): ?array
    {
        if (! $this->transitionsEnforced() || ! $order->delivery_status_id) {
            return null;
        }
        $ids = StatusTransition::query()->where('from_status_id', $order->delivery_status_id)->pluck('to_status_id')->all();

        return $ids ? array_map('intval', $ids) : null;
    }

    public function changeConfirmation(Order $order, string $confirmation): Order
    {
        return DB::transaction(function () use ($order, $confirmation) {
            if ($order->confirmation_status === $confirmation) {
                return $order;
            }
            $order->confirmation_status = $confirmation;
            $order->status_changed_at = now();
            if ($confirmation === 'confirmee') {
                $order->confirmed_at ??= now();
            }
            $order->save();

            $meta = Catalog::CONFIRMATION_STATUSES[$confirmation];
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'kind' => 'confirmation',
                'status_code' => $confirmation,
                'status_name' => $meta['label'],
                'status_color' => $meta['color'],
                'user_id' => CurrentUser::id(),
            ]);

            if ($confirmation === 'confirmee' && ! $order->delivery_status_id && ($initial = $this->statusOnConfirm())) {
                $this->applyStatus($order, $initial, [], 'Commande confirmée');
            }

            return $order;
        });
    }

    public function assignDriver(Order $order, ?int $driverId): Order
    {
        return DB::transaction(function () use ($order, $driverId) {
            $order->driver_id = $driverId;
            $order->save();
            $this->syncDeliveryMission($order, $driverId);

            $current = $order->deliveryStatus;
            if ($driverId && (! $current || $current->category === 'avant_livraison')) {
                $assign = $this->statusOnAssign();
                if ($assign && $assign->id !== $order->delivery_status_id) {
                    $this->applyStatus($order, $assign, [], 'Attribution au livreur');
                }
            }

            return $order;
        });
    }

    /**
     * Manual status change: checks the status is active, the transition is allowed
     * and the status' required fields are provided, then records the history.
     */
    public function changeStatus(Order $order, DeliveryStatus $status, array $data = []): Order
    {
        if (! $status->is_active) {
            throw ValidationException::withMessages(['delivery_status_id' => 'Ce statut est désactivé.']);
        }
        $allowed = $this->allowedStatusIds($order);
        if ($allowed !== null && ! in_array($status->id, $allowed, true)) {
            throw ValidationException::withMessages([
                'delivery_status_id' => "Transition non autorisée depuis « {$order->deliveryStatus?->name} » vers « {$status->name} ».",
            ]);
        }

        $errors = [];
        foreach ($status->requiredFields() as $field) {
            $missing = match ($field) {
                'postponed_at' => empty($data['postponed_at']),
                'collected_amount' => ! isset($data['collected_amount']) || $data['collected_amount'] === '',
                default => blank($data[$field] ?? null),
            };
            if ($missing) {
                $label = Catalog::REQUIRED_FIELDS[$field] ?? $field;
                $key = $field === 'postponed_at' ? 'postponed_date' : $field;
                $errors[$key] = "« {$label} » est obligatoire pour le statut « {$status->name} ».";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($order, $status, $data) {
            $this->applyStatus($order, $status, $data, $data['note'] ?? null);

            return $order;
        });
    }

    protected function applyStatus(Order $order, DeliveryStatus $status, array $data, ?string $note): void
    {
        $from = $order->delivery_status_id ? DeliveryStatus::find($order->delivery_status_id) : null;

        $order->delivery_status_id = $status->id;
        $order->setRelation('deliveryStatus', $status);
        $order->status_changed_at = now();

        if (array_key_exists('reason', $data)) {
            $order->status_reason = $data['reason'];
        }
        if (! empty($data['postponed_at'])) {
            $order->postponed_at = Carbon::parse($data['postponed_at']);
        }
        if ($status->category === 'succes') {
            $order->delivered_at ??= now();
            if (isset($data['collected_amount']) && $data['collected_amount'] !== '') {
                $order->collected_amount = $data['collected_amount'];
            } elseif ($order->collected_amount === null) {
                $order->collected_amount = $order->payment_method === 'cod' ? $order->amount : 0;
            }
        }
        $order->save();

        $historyData = array_filter([
            'reason' => $data['reason'] ?? null,
            'postponed_at' => ! empty($data['postponed_at']) ? Carbon::parse($data['postponed_at'])->format('Y-m-d H:i') : null,
            'collected_amount' => isset($data['collected_amount']) && $data['collected_amount'] !== '' ? (float) $data['collected_amount'] : null,
        ], fn ($v) => $v !== null && $v !== '');

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'kind' => 'livraison',
            'delivery_status_id' => $status->id,
            'status_code' => $status->code,
            'status_name' => $status->name,
            'status_color' => $status->color,
            'status_category' => $status->category,
            'from_status_id' => $from?->id,
            'from_status_name' => $from?->name,
            'data' => $historyData ?: null,
            'note' => $note,
            'user_id' => CurrentUser::id(),
        ]);

        $this->syncMissionsWithStatus($order, $status);
    }

    /**
     * Assigning a driver to an order creates (or re-assigns) its "livraison" mission,
     * which snapshots the driver's current Livraison tariff.
     */
    protected function syncDeliveryMission(Order $order, ?int $driverId): void
    {
        $mission = $order->missions()->where('type', 'livraison')->latest('id')->first();

        if (! $mission) {
            if (! $driverId) {
                return;
            }
            $mission = Mission::create([
                'type' => 'livraison',
                'status' => 'a_faire',
                'order_id' => $order->id,
                'contact_name' => $order->customer_name,
                'phone' => $order->customer_phone,
                'address' => $order->address,
                'city' => $order->city,
                'items_description' => $order->product_name,
                'quantity' => $order->quantity,
                'scheduled_date' => now()->toDateString(),
                'cash_amount' => $order->payment_method === 'cod' ? $order->amount : null,
                'cash_direction' => $order->payment_method === 'cod' ? 'collect' : null,
            ]);
        }

        $this->missions->assign($mission, $driverId);
    }

    /** Keeps the order's missions aligned with its delivery status (category-driven, no status codes). */
    protected function syncMissionsWithStatus(Order $order, DeliveryStatus $status): void
    {
        $mission = $order->missions()->where('type', 'livraison')->latest('id')->first();
        $target = self::MISSION_STATUS_BY_CATEGORY[$status->category] ?? null;
        if ($mission && $target && ! $mission->closing_id && $mission->status !== $target) {
            $this->missions->changeStatus($mission, $target, "Statut commande : {$status->name}");
        }

        $type = $status->creates_mission_type;
        if ($type && $order->driver_id) {
            $exists = $order->missions()->where('type', $type)->whereIn('status', Catalog::openMissionStatuses())->exists();
            if (! $exists) {
                $this->missions->create([
                    'type' => $type,
                    'order_id' => $order->id,
                    'driver_id' => $order->driver_id,
                    'contact_name' => $order->customer_name,
                    'phone' => $order->customer_phone,
                    'address' => $order->address,
                    'city' => $order->city,
                    'items_description' => $order->product_name,
                    'quantity' => $order->quantity,
                    'scheduled_date' => now()->toDateString(),
                ]);
            }
        }
    }
}

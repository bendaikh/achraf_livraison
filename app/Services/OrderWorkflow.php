<?php

namespace App\Services;

use App\Models\ConfirmationStatus;
use App\Models\DeliveryStatus;
use App\Models\Driver;
use App\Models\Mission;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Setting;
use App\Models\StatusTransition;
use App\Models\User;
use App\Support\Catalog;
use App\Support\CurrentUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single entry point for order confirmation / assignment / delivery-status changes, used by
 * the Commandes screens, the Confirmation & Affectation screens and the driver space
 * (Mes missions). Statuses are configurable (delivery_statuses); behaviour depends only on
 * the status category.
 */
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

    /* ------------------------------------------------------------ configuration */

    /** Status automatically applied when an order gets confirmed (configurable). */
    public function statusOnConfirm(): ?DeliveryStatus
    {
        $id = Setting::getValue('status_on_confirm_id');

        return ($id ? DeliveryStatus::active()->find($id) : null)
            ?? DeliveryStatus::firstActiveOfCategory('avant_livraison');
    }

    /** Status automatically applied when a driver is assigned (configurable). */
    public function statusOnAssign(): ?DeliveryStatus
    {
        $id = Setting::getValue('status_on_assign_id');

        return $id ? DeliveryStatus::active()->find($id) : null;
    }

    /** Status used for a driver action (Mes missions): setting, else first active status of the category. */
    public function statusForDriverAction(string $action): DeliveryStatus
    {
        $ids = (array) Setting::getValue('driver_action_status_ids', []);
        $status = ! empty($ids[$action]) ? DeliveryStatus::active()->find($ids[$action]) : null;
        $category = Catalog::DRIVER_ACTIONS[$action]['category'] ?? null;
        $status ??= $category ? DeliveryStatus::firstActiveOfCategory($category) : null;

        if (! $status) {
            throw ValidationException::withMessages([
                'status' => 'Aucun statut actif configuré pour l’action « '.(Catalog::DRIVER_ACTIONS[$action]['label'] ?? $action).' » (Paramètres → Statuts de livraison).',
            ]);
        }

        return $status;
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
        $current = $order->deliveryStatusDefinition();
        if (! $this->transitionsEnforced() || ! $current) {
            return null;
        }
        $ids = StatusTransition::query()->where('from_status_id', $current->id)->pluck('to_status_id')->all();

        return $ids ? array_map('intval', $ids) : null;
    }

    /* ------------------------------------------------------------ confirmation */

    /**
     * Generic confirmation status change (Commandes / fiche commande). The Confirmation queue
     * keeps its dedicated actions (ConfirmationController) which call recordConfirmation().
     */
    public function changeConfirmation(Order $order, string $code, ?User $user = null, array $extra = []): Order
    {
        $status = ConfirmationStatus::query()->where('code', $code)->first();
        if (! $status || ! $status->is_active) {
            throw ValidationException::withMessages(['confirmation_status' => 'Statut de confirmation inconnu ou désactivé.']);
        }
        if ($order->confirmation_status === $status->code) {
            return $order;
        }
        $user ??= CurrentUser::get();

        return DB::transaction(function () use ($order, $status, $user, $extra) {
            $order->confirmation_status = $status->code;
            $order->confirmation_acted_by = $user?->id;
            $order->confirmation_acted_at = now();
            if ($status->type === ConfirmationStatus::TYPE_SUCCESS) {
                $order->confirmed_by ??= $user?->id;
                $order->confirmed_at ??= now();
                $order->postponed_until = null;
                $order->cancellation_reason = null;
            }
            if ($status->type === ConfirmationStatus::TYPE_CANCELLED && ! empty($extra['reason'])) {
                $order->cancellation_reason = $extra['reason'];
            }
            if ($status->queue_behavior === ConfirmationStatus::BEHAVIOR_FUTURE_ONLY && ! empty($extra['recall_at'])) {
                $order->postponed_until = Carbon::parse($extra['recall_at']);
            }
            $order->appendHistory($status->code === Order::CONFIRMATION_CONFIRMED ? 'confirmed' : $status->code, $status->name, $user);
            $order->save();

            $this->recordConfirmation($order, $status, $user);

            return $order;
        });
    }

    /**
     * Records a confirmation change in the immutable status history and, when the order is
     * confirmed, applies the configured initial delivery status ("À attribuer").
     */
    public function recordConfirmation(Order $order, ConfirmationStatus $status, ?User $user = null): void
    {
        $order->status_changed_at = now();
        $order->saveQuietly();

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'kind' => 'confirmation',
            'status_code' => $status->code,
            'status_name' => $status->name,
            'status_color' => $status->color,
            'user_id' => $user?->id ?? CurrentUser::id(),
        ]);

        if ($status->type === ConfirmationStatus::TYPE_SUCCESS && ! $order->delivery_status && ($initial = $this->statusOnConfirm())) {
            $this->applyStatus($order, $initial, [], 'Commande confirmée', $user);
        }
    }

    /* ------------------------------------------------------------ assignment */

    public function assignDriver(Order $order, ?int $driverId, ?User $user = null): Order
    {
        $user ??= CurrentUser::get();

        return DB::transaction(function () use ($order, $driverId, $user) {
            $driver = $driverId ? Driver::findOrFail($driverId) : null;
            $changed = (int) $order->driver_id !== (int) $driverId;

            $order->driver_id = $driverId;
            if ($driver && ($changed || ! $order->assigned_at || $order->isAwaitingAssignment())) {
                $order->forceFill([
                    'assigned_by' => $user?->id,
                    'assigned_at' => now(),
                    'delivery_taken_at' => null,
                    'delivery_postponed_until' => null,
                    'delivery_failure_reason' => null,
                ]);
                $order->appendHistory('delivery_assigned', sprintf('Affectée à %s par %s', $driver->name, $user?->name ?? 'Lavfast'), $user, [
                    'driver_id' => $driver->id,
                    'driver_name' => $driver->name,
                ]);
            }
            $order->save();
            $this->syncDeliveryMission($order, $driverId);

            $category = $order->deliveryCategory();
            if ($driverId && ($category === null || in_array($category, Catalog::ASSIGNABLE_CATEGORIES, true))) {
                $assign = $this->statusOnAssign();
                if ($assign && $assign->code !== $order->delivery_status) {
                    $this->applyStatus($order, $assign, [], 'Attribution à '.$driver->name, $user);
                }
            }

            return $order;
        });
    }

    /* ------------------------------------------------------------ delivery status */

    /**
     * Manual status change: checks the status is active, the transition is allowed
     * and the status' required fields are provided, then records the history.
     */
    public function changeStatus(Order $order, DeliveryStatus $status, array $data = [], ?User $user = null): Order
    {
        if (! $status->is_active) {
            throw ValidationException::withMessages(['delivery_status_id' => 'Ce statut est désactivé.']);
        }
        $allowed = $this->allowedStatusIds($order);
        if ($allowed !== null && ! in_array($status->id, $allowed, true)) {
            throw ValidationException::withMessages([
                'delivery_status_id' => "Transition non autorisée depuis « {$order->deliveryStatusDefinition()?->name} » vers « {$status->name} ».",
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

        return DB::transaction(function () use ($order, $status, $data, $user) {
            $this->applyStatus($order, $status, $data, $data['note'] ?? null, $user);

            return $order;
        });
    }

    /** Driver "prise en charge" on his first action: moves an assigned order to the "en livraison" status. */
    public function takeByDriver(Order $order, ?User $user = null): void
    {
        if ($order->delivery_taken_at) {
            return;
        }
        $order->delivery_taken_at = now();
        $order->appendHistory('delivery_taken', 'Prise en charge par '.($order->driver?->name ?? $user?->name ?? 'Livreur'), $user);
        $order->save();

        if ($order->deliveryCategory() === 'avant_livraison') {
            $this->applyStatus($order, $this->statusForDriverAction('take'), [], 'Prise en charge', $user);
        }
    }

    /**
     * Driver action from "Mes missions": prise en charge (first action) + configured status for
     * the action, with the status' required fields enforced. All or nothing.
     */
    public function driverAction(Order $order, string $action, array $data, ?User $user = null): DeliveryStatus
    {
        return DB::transaction(function () use ($order, $action, $data, $user) {
            $this->takeByDriver($order, $user);
            $status = $this->statusForDriverAction($action);
            $this->changeStatus($order, $status, $data, $user);

            return $status;
        });
    }

    protected function applyStatus(Order $order, DeliveryStatus $status, array $data, ?string $note, ?User $user = null): void
    {
        $from = $order->deliveryStatusDefinition();

        $order->delivery_status = $status->code;
        $order->setRelation('deliveryStatus', $status);
        $order->status_changed_at = now();

        if (array_key_exists('reason', $data)) {
            $order->delivery_failure_reason = $data['reason'];
        } elseif (in_array($status->category, ['succes', 'en_livraison', 'report'], true)) {
            $order->delivery_failure_reason = null;
        }
        if (! empty($data['postponed_at'])) {
            $order->delivery_postponed_until = Carbon::parse($data['postponed_at']);
        } elseif ($status->category !== 'report') {
            $order->delivery_postponed_until = null;
        }
        if ($status->category === 'succes') {
            $order->delivered_at ??= now();
            if (isset($data['collected_amount']) && $data['collected_amount'] !== '') {
                $order->amount_collected = $data['collected_amount'];
            } elseif ($order->amount_collected === null) {
                $order->amount_collected = $order->isCod() ? $order->total_price : 0;
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
            'user_id' => $user?->id ?? CurrentUser::id(),
        ]);

        $this->syncMissionsWithStatus($order, $status);
    }

    /**
     * Assigning a driver to an order creates (or re-assigns) its open "livraison" mission,
     * which snapshots the driver's current Livraison tariff. A finished attempt (failed,
     * returned…) is never re-priced: a new mission is created for the new attempt.
     */
    protected function syncDeliveryMission(Order $order, ?int $driverId): void
    {
        $mission = $order->missions()->where('type', 'livraison')
            ->whereIn('status', Catalog::openMissionStatuses())->whereNull('closing_id')
            ->latest('id')->first();

        if (! $mission) {
            if (! $driverId) {
                return;
            }
            $mission = Mission::create([
                'type' => 'livraison',
                'status' => 'a_faire',
                'order_id' => $order->id,
                'contact_name' => $order->customer_name,
                'phone' => $order->phone,
                'address' => $order->shippingAddressLine(),
                'city' => $order->shippingCity(),
                'items_description' => $order->productName(),
                'quantity' => $order->itemsQuantity(),
                'scheduled_date' => now()->toDateString(),
                'cash_amount' => $order->isCod() ? $order->total_price : null,
                'cash_direction' => $order->isCod() ? 'collect' : null,
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
                    'phone' => $order->phone,
                    'address' => $order->shippingAddressLine(),
                    'city' => $order->shippingCity(),
                    'items_description' => $order->productName(),
                    'quantity' => $order->itemsQuantity(),
                    'scheduled_date' => now()->toDateString(),
                ]);
            }
        }
    }
}

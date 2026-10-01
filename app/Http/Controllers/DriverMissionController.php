<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class DriverMissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $driver = $this->resolveDriver($request);
        $perPage = min(max((int) $request->integer('per_page', 50), 1), 100);
        $search = trim((string) $request->query('search', ''));
        $filter = trim((string) $request->query('filter', 'active'));

        $query = Order::query()
            ->where('driver_id', $driver->id)
            ->latest('assigned_at');

        match ($filter) {
            'delivered' => $query->where('delivery_status', Order::DELIVERY_DELIVERED),
            'history' => $query->whereIn('delivery_status', [
                Order::DELIVERY_DELIVERED,
                Order::DELIVERY_NO_ANSWER,
                Order::DELIVERY_FAILED,
            ]),
            default => $query->whereIn('delivery_status', Order::DELIVERY_ACTIVE_STATUSES),
        };

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);
        $orders = collect($paginator->items())->map(fn (Order $order) => $this->payload($order));

        return response()->json([
            'orders' => $orders,
            'driver' => [
                'id' => $driver->id,
                'name' => $driver->name,
                'phone' => $driver->phone,
                'cod_held' => round($driver->codHeldAmount(), 2),
            ],
            'counts' => [
                'active' => Order::query()
                    ->where('driver_id', $driver->id)
                    ->whereIn('delivery_status', Order::DELIVERY_ACTIVE_STATUSES)
                    ->count(),
                'delivered' => Order::query()
                    ->where('driver_id', $driver->id)
                    ->where('delivery_status', Order::DELIVERY_DELIVERED)
                    ->count(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'filter' => $filter,
            ],
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $driver = $this->resolveDriver($request);
        $this->assertOwnsOrder($order, $driver);

        return response()->json([
            'order' => $this->payload($order),
        ]);
    }

    public function deliver(Request $request, Order $order): JsonResponse
    {
        $driver = $this->resolveDriver($request);
        $this->assertActiveMission($order, $driver);

        $data = $request->validate([
            'amount_collected' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'amount_collected.required' => 'Indiquez le montant réellement encaissé.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $amount = round((float) $data['amount_collected'], 2);
        $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';

        $order->ensureTakenByDriver($user);

        $order->forceFill([
            'delivery_status' => Order::DELIVERY_DELIVERED,
            'amount_collected' => $amount,
            'delivered_at' => now(),
            'delivery_postponed_until' => null,
            'delivery_failure_reason' => null,
            'cod_remitted_at' => null,
        ]);

        $currency = $order->currency ?: 'MAD';
        $label = sprintf('Livrée – %s %s encaissés', $this->formatAmount($amount), $currency);

        $order->appendHistory('delivery_delivered', $label, $user, [
            'amount_collected' => $amount,
            'comment' => $comment !== '' ? $comment : null,
        ], $comment !== '' ? $comment : null);
        $order->save();

        return response()->json([
            'order' => $this->payload($order->fresh()),
            'message' => 'Livraison enregistrée. Le COD reste chez vous jusqu’à la clôture.',
        ]);
    }

    public function postpone(Request $request, Order $order): JsonResponse
    {
        $driver = $this->resolveDriver($request);
        $this->assertActiveMission($order, $driver);

        $data = $request->validate([
            'postpone_at' => ['required', 'date', 'after:now'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'postpone_at.required' => 'La nouvelle date et heure sont obligatoires.',
            'postpone_at.after' => 'La date de report doit être dans le futur.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $postponeAt = Carbon::parse($data['postpone_at']);
        $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';

        $order->ensureTakenByDriver($user);

        $order->forceFill([
            'delivery_status' => Order::DELIVERY_POSTPONED,
            'delivery_postponed_until' => $postponeAt,
            'delivery_failure_reason' => null,
        ]);

        $label = sprintf(
            'Reportée au %s',
            $postponeAt->timezone(config('app.timezone'))->format('d/m/Y \à H:i'),
        );

        $order->appendHistory('delivery_postponed', $label, $user, [
            'postpone_at' => $postponeAt->toIso8601String(),
            'comment' => $comment !== '' ? $comment : null,
        ], $comment !== '' ? $comment : null);
        $order->save();

        return response()->json([
            'order' => $this->payload($order->fresh()),
            'message' => 'Commande reportée — elle reste chez vous.',
        ]);
    }

    public function noAnswer(Request $request, Order $order): JsonResponse
    {
        return $this->markUnsuccessful(
            $request,
            $order,
            Order::DELIVERY_NO_ANSWER,
            'Client pas de réponse',
            'delivery_no_answer',
        );
    }

    public function fail(Request $request, Order $order): JsonResponse
    {
        return $this->markUnsuccessful(
            $request,
            $order,
            Order::DELIVERY_FAILED,
            'Échouée',
            'delivery_failed',
        );
    }

    private function markUnsuccessful(
        Request $request,
        Order $order,
        string $status,
        string $defaultLabel,
        string $historyType,
    ): JsonResponse {
        $driver = $this->resolveDriver($request);
        $this->assertActiveMission($order, $driver);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Le motif est obligatoire.',
            'reason.min' => 'Le motif est trop court.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $reason = trim($data['reason']);
        $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';

        $order->ensureTakenByDriver($user);

        $order->forceFill([
            'delivery_status' => $status,
            'delivery_failure_reason' => $reason,
            'delivery_postponed_until' => null,
            // Conservé pour l’historique / stats ; la commande redevient réattribuable.
        ]);

        $historyComment = $comment !== '' ? $reason.' — '.$comment : $reason;
        $label = $defaultLabel;

        $order->appendHistory($historyType, $label, $user, [
            'reason' => $reason,
            'comment' => $comment !== '' ? $comment : null,
            'driver_id' => $driver->id,
            'driver_name' => $driver->name,
        ], $historyComment);
        $order->save();

        return response()->json([
            'order' => $this->payload($order->fresh()),
            'message' => $status === Order::DELIVERY_NO_ANSWER
                ? 'Pas de réponse enregistré. La commande peut être réattribuée.'
                : 'Échec enregistré. La commande peut être réattribuée.',
        ]);
    }

    private function resolveDriver(Request $request): Driver
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isLivreur()) {
            $driver = $user->driver;
            if (! $driver) {
                throw ValidationException::withMessages([
                    'driver' => 'Aucun profil livreur associé.',
                ]);
            }
            if (! $driver->is_active) {
                throw ValidationException::withMessages([
                    'driver' => 'Votre compte livreur est inactif.',
                ]);
            }

            return $driver;
        }

        // Admin preview: optional ?driver_id=
        $driverId = $request->integer('driver_id');
        if ($driverId > 0) {
            return Driver::query()->findOrFail($driverId);
        }

        throw ValidationException::withMessages([
            'driver' => 'Profil livreur requis.',
        ]);
    }

    private function assertOwnsOrder(Order $order, Driver $driver): void
    {
        $ownsActive = (int) $order->driver_id === (int) $driver->id;
        $ownsHistory = $order->delivery_status === Order::DELIVERY_DELIVERED
            && (int) $order->driver_id === (int) $driver->id;

        if (! $ownsActive && ! $ownsHistory) {
            // Allow viewing delivered still linked, or active
            abort(403, 'Cette mission ne vous est pas attribuée.');
        }
    }

    private function assertActiveMission(Order $order, Driver $driver): void
    {
        if ((int) $order->driver_id !== (int) $driver->id) {
            abort(403, 'Cette mission ne vous est pas attribuée.');
        }

        if (! in_array($order->delivery_status, Order::DELIVERY_ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'order' => 'Cette mission n’est plus active.',
            ]);
        }
    }

    private function payload(Order $order): array
    {
        return [
            'id' => $order->id,
            'name' => $order->name,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'phone' => $order->phone,
            'city' => $order->shippingCity(),
            'address' => $order->shippingAddressLine(),
            'shipping_address' => $order->shipping_address,
            'total_price' => $order->total_price,
            'currency' => $order->currency,
            'line_items' => $order->line_items ?? [],
            'note' => $order->note,
            'internal_note' => $order->internal_note,
            'delivery_status' => $order->delivery_status,
            'delivery_status_label' => $order->deliveryStatusLabel(),
            'delivery_status_color' => $order->deliveryStatusColor(),
            'delivery_postponed_until' => $order->delivery_postponed_until?->toIso8601String(),
            'delivery_failure_reason' => $order->delivery_failure_reason,
            'amount_collected' => $order->amount_collected,
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'assigned_at' => $order->assigned_at?->toIso8601String(),
            'confirmation_history' => $order->confirmation_history ?? [],
            'can_act' => in_array($order->delivery_status, Order::DELIVERY_ACTIVE_STATUSES, true),
        ];
    }

    private function formatAmount(float $amount): string
    {
        if (abs($amount - round($amount)) < 0.001) {
            return (string) (int) round($amount);
        }

        return number_format($amount, 2, '.', '');
    }
}

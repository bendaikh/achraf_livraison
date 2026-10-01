<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderWorkflow;
use App\Support\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class DriverMissionController extends Controller
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $driver = $this->resolveDriver($request);
        $perPage = min(max((int) $request->integer('per_page', 50), 1), 100);
        $search = trim((string) $request->query('search', ''));
        $filter = trim((string) $request->query('filter', 'active'));

        $query = Order::query()
            ->with('deliveryStatus')
            ->where('driver_id', $driver->id)
            ->latest('assigned_at');

        match ($filter) {
            'delivered' => $query->inDeliveryCategories(['succes']),
            'history' => $query->inDeliveryCategories(Catalog::DRIVER_HISTORY_CATEGORIES),
            default => $query->activeWithDriver(),
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
                'active' => Order::query()->forDriver($driver->id)->count(),
                'delivered' => Order::query()
                    ->where('driver_id', $driver->id)
                    ->inDeliveryCategories(['succes'])
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

        $order->forceFill(['delivered_at' => now(), 'cod_remitted_at' => null]);
        $this->workflow->driverAction($order, 'deliver', [
            'collected_amount' => $amount,
            'note' => $comment !== '' ? $comment : null,
        ], $user);

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

        $this->workflow->driverAction($order, 'postpone', [
            'postponed_at' => $postponeAt->copy()->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'note' => $comment !== '' ? $comment : null,
        ], $user);

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
            'no_answer',
            'Client pas de réponse',
            'delivery_no_answer',
        );
    }

    public function fail(Request $request, Order $order): JsonResponse
    {
        return $this->markUnsuccessful(
            $request,
            $order,
            'fail',
            'Échouée',
            'delivery_failed',
        );
    }

    private function markUnsuccessful(
        Request $request,
        Order $order,
        string $action,
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

        // Conservé pour l’historique / stats ; la commande redevient réattribuable.
        $status = $this->workflow->driverAction($order, $action, [
            'reason' => $reason,
            'note' => $comment !== '' ? $comment : null,
        ], $user);

        $historyComment = $comment !== '' ? $reason.' — '.$comment : $reason;
        $label = $status->name ?: $defaultLabel;

        $order->appendHistory($historyType, $label, $user, [
            'reason' => $reason,
            'comment' => $comment !== '' ? $comment : null,
            'driver_id' => $driver->id,
            'driver_name' => $driver->name,
        ], $historyComment);
        $order->save();

        return response()->json([
            'order' => $this->payload($order->fresh()),
            'message' => $action === 'no_answer'
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
        $ownsHistory = $order->deliveryCategory() === 'succes'
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

        if (! $order->isActiveWithDriver()) {
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
            'can_act' => $order->isActiveWithDriver(),
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

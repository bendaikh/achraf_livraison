<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Order extends Model
{
    /** System action codes (seeded). Prefer ConfirmationStatus lookups for labels/colors. */
    public const CONFIRMATION_TO_CONFIRM = 'to_confirm';

    public const CONFIRMATION_NO_ANSWER = 'no_answer';

    public const CONFIRMATION_POSTPONED = 'postponed';

    public const CONFIRMATION_CONFIRMED = 'confirmed';

    public const CONFIRMATION_CANCELLED = 'cancelled';

    /** Local delivery workflow statuses (Lavfast livreurs). */
    public const DELIVERY_ASSIGNED = 'assigned';

    public const DELIVERY_IN_PROGRESS = 'in_progress';

    public const DELIVERY_POSTPONED = 'postponed';

    public const DELIVERY_NO_ANSWER = 'no_answer';

    public const DELIVERY_FAILED = 'failed';

    public const DELIVERY_DELIVERED = 'delivered';

    /** Statuts où la commande est encore chez un livreur. */
    public const DELIVERY_ACTIVE_STATUSES = [
        self::DELIVERY_ASSIGNED,
        self::DELIVERY_IN_PROGRESS,
        self::DELIVERY_POSTPONED,
    ];

    /** Statuts permettant une (ré)affectation. */
    public const DELIVERY_ASSIGNABLE_STATUSES = [
        null,
        self::DELIVERY_NO_ANSWER,
        self::DELIVERY_FAILED,
    ];

    public const DELIVERY_LABELS = [
        self::DELIVERY_ASSIGNED => 'Attribuée',
        self::DELIVERY_IN_PROGRESS => 'En cours',
        self::DELIVERY_POSTPONED => 'Reportée',
        self::DELIVERY_NO_ANSWER => 'Pas de réponse',
        self::DELIVERY_FAILED => 'Échouée',
        self::DELIVERY_DELIVERED => 'Livrée',
    ];

    public const DELIVERY_COLORS = [
        self::DELIVERY_ASSIGNED => '#2563eb',
        self::DELIVERY_IN_PROGRESS => '#0891b2',
        self::DELIVERY_POSTPONED => '#d97706',
        self::DELIVERY_NO_ANSWER => '#64748b',
        self::DELIVERY_FAILED => '#e11d48',
        self::DELIVERY_DELIVERED => '#059669',
    ];

    protected $fillable = [
        'shopify_shop_id',
        'shopify_order_id',
        'order_number',
        'name',
        'email',
        'phone',
        'customer_name',
        'financial_status',
        'fulfillment_status',
        'status',
        'confirmation_status',
        'delivery_status',
        'driver_id',
        'assigned_by',
        'assigned_at',
        'delivery_taken_at',
        'delivery_postponed_until',
        'delivery_failure_reason',
        'amount_collected',
        'delivered_at',
        'cod_remitted_at',
        'total_price',
        'shipping_price',
        'currency',
        'shipping_address',
        'line_items',
        'note',
        'internal_note',
        'confirmation_history',
        'confirmed_by',
        'confirmed_at',
        'confirmation_acted_by',
        'confirmation_acted_at',
        'postponed_until',
        'cancellation_reason',
        'shopify_created_at',
        'shopify_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'line_items' => 'array',
            'confirmation_history' => 'array',
            'total_price' => 'decimal:2',
            'shipping_price' => 'decimal:2',
            'amount_collected' => 'decimal:2',
            'shopify_created_at' => 'datetime',
            'shopify_updated_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'confirmation_acted_at' => 'datetime',
            'postponed_until' => 'datetime',
            'assigned_at' => 'datetime',
            'delivery_taken_at' => 'datetime',
            'delivery_postponed_until' => 'datetime',
            'delivered_at' => 'datetime',
            'cod_remitted_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopifyShop::class, 'shopify_shop_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function confirmationActedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmation_acted_by');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function confirmationStatusDefinition(): ?ConfirmationStatus
    {
        return ConfirmationStatus::findByCode($this->confirmation_status);
    }

    public function isDueForConfirmation(): bool
    {
        $definition = $this->confirmationStatusDefinition();

        if (! $definition) {
            return $this->confirmation_status === self::CONFIRMATION_TO_CONFIRM;
        }

        if ($definition->queue_behavior === ConfirmationStatus::BEHAVIOR_DUE_QUEUE || $definition->is_default) {
            return true;
        }

        return $definition->queue_behavior === ConfirmationStatus::BEHAVIOR_FUTURE_ONLY
            && $this->postponed_until
            && $this->postponed_until->lte(now());
    }

    public function canPerformConfirmationActions(): bool
    {
        $definition = $this->confirmationStatusDefinition();

        if ($definition) {
            return ! $definition->is_terminal && $definition->is_active;
        }

        return ! in_array($this->confirmation_status, [
            self::CONFIRMATION_CONFIRMED,
            self::CONFIRMATION_CANCELLED,
        ], true);
    }

    public function isAwaitingAssignment(): bool
    {
        if ($this->confirmation_status !== self::CONFIRMATION_CONFIRMED) {
            return false;
        }

        if (in_array($this->delivery_status, self::DELIVERY_ACTIVE_STATUSES, true)) {
            return false;
        }

        if ($this->delivery_status === self::DELIVERY_DELIVERED) {
            return false;
        }

        return true;
    }

    public function canBeAssigned(): bool
    {
        return $this->isAwaitingAssignment();
    }

    public function isActiveWithDriver(): bool
    {
        return $this->driver_id
            && in_array($this->delivery_status, self::DELIVERY_ACTIVE_STATUSES, true);
    }

    public function deliveryStatusLabel(): string
    {
        if (! $this->delivery_status) {
            return 'À attribuer';
        }

        return self::DELIVERY_LABELS[$this->delivery_status] ?? $this->delivery_status;
    }

    public function deliveryStatusColor(): string
    {
        if (! $this->delivery_status) {
            return '#64748b';
        }

        return self::DELIVERY_COLORS[$this->delivery_status] ?? '#64748b';
    }

    /**
     * Mark prise en charge on first livreur action if not already taken.
     */
    public function ensureTakenByDriver(?User $user = null): void
    {
        if ($this->delivery_taken_at) {
            return;
        }

        if ($this->delivery_status === self::DELIVERY_ASSIGNED) {
            $this->delivery_status = self::DELIVERY_IN_PROGRESS;
        }

        $this->delivery_taken_at = now();

        $driverName = $this->driver?->name ?? $user?->name ?? 'Livreur';
        $this->appendHistory('delivery_taken', 'Prise en charge par '.$driverName, $user);
    }

    public function appendHistory(string $type, string $label, ?User $user = null, array $meta = [], ?string $comment = null): void
    {
        $history = $this->confirmation_history ?? [];

        $entry = [
            'type' => $type,
            'label' => $label,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'at' => now()->toIso8601String(),
            'comment' => $comment,
            'meta' => $meta ?: null,
        ];

        $history[] = array_filter($entry, fn ($value) => $value !== null);

        $this->confirmation_history = $history;
    }

    public function shippingAddressLine(): ?string
    {
        $address = $this->shipping_address;
        if (! is_array($address)) {
            return null;
        }

        $parts = array_filter([
            $address['address1'] ?? null,
            $address['address2'] ?? null,
        ]);

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    public function shippingCity(): ?string
    {
        return data_get($this->shipping_address, 'city');
    }

    public static function confirmationLabel(string $status): string
    {
        return ConfirmationStatus::labelFor($status);
    }

    public function postponedUntilFormatted(): ?string
    {
        if (! $this->postponed_until instanceof Carbon) {
            return null;
        }

        return $this->postponed_until->timezone(config('app.timezone'))->format('d/m/Y H:i');
    }

    public function deliveryPostponedUntilFormatted(): ?string
    {
        if (! $this->delivery_postponed_until instanceof Carbon) {
            return null;
        }

        return $this->delivery_postponed_until->timezone(config('app.timezone'))->format('d/m/Y H:i');
    }

    public function scopeAwaitingAssignment($query)
    {
        return $query
            ->where('confirmation_status', self::CONFIRMATION_CONFIRMED)
            ->where(function ($q) {
                $q->whereNull('delivery_status')
                    ->orWhereIn('delivery_status', [
                        self::DELIVERY_NO_ANSWER,
                        self::DELIVERY_FAILED,
                    ]);
            });
    }

    public function scopeForDriver($query, int $driverId)
    {
        return $query
            ->where('driver_id', $driverId)
            ->whereIn('delivery_status', self::DELIVERY_ACTIVE_STATUSES);
    }
}

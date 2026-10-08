<?php

namespace App\Models;

use App\Services\Clients\ClientService;
use App\Services\Payments\AmountDue;
use App\Support\Catalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Orders come from Shopify (shopify_shop_id) or are created manually in Lav'Fast Flow.
 *
 * Delivery status: `delivery_status` holds the *code* of a configurable DeliveryStatus
 * (Paramètres → Statuts de livraison). Behaviour is driven by the status category, never by
 * a hard-coded list of codes. Changes go through App\Services\OrderWorkflow.
 */
class Order extends Model
{
    /** System action codes (seeded). Prefer ConfirmationStatus lookups for labels/colors. */
    public const CONFIRMATION_TO_CONFIRM = 'to_confirm';

    public const CONFIRMATION_NO_ANSWER = 'no_answer';

    public const CONFIRMATION_POSTPONED = 'postponed';

    public const CONFIRMATION_CONFIRMED = 'confirmed';

    public const CONFIRMATION_CANCELLED = 'cancelled';

    protected $fillable = [
        'shopify_shop_id',
        'shopify_order_id',
        'source',
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
        'carrier',
        'assigned_user_id',
        'confirmation_channel',
        'discount_total',
        'assigned_by',
        'assigned_at',
        'delivery_taken_at',
        'delivery_postponed_until',
        'delivery_failure_reason',
        'amount_collected',
        'delivered_at',
        'status_changed_at',
        'cod_remitted_at',
        'closing_id',
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
        'items_edited_at',
        'items_edited_by',
        'shopify_line_items',
        'company_id',
        'shopify_sync_status',
        'shopify_sync_error',
        'shopify_synced_at',
        'shopify_customer_id',
        'amount_paid',
        'amount_due',
        'total_outstanding',
        'payment_gateway_names',
        'tags',
        'discount_applications',
        'shopify_refunds',
        'amount_due_stale',
    ];

    protected $attributes = [
        'confirmation_status' => self::CONFIRMATION_TO_CONFIRM,
        'status' => 'pending',
        'total_price' => 0,
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'line_items' => 'array',
            'shopify_line_items' => 'array',
            'items_edited_at' => 'datetime',
            'confirmation_history' => 'array',
            'total_price' => 'decimal:2',
            'shipping_price' => 'decimal:2',
            'amount_collected' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'total_outstanding' => 'decimal:2',
            'payment_gateway_names' => 'array',
            'discount_applications' => 'array',
            'shopify_refunds' => 'array',
            'amount_due_stale' => 'boolean',
            'shopify_synced_at' => 'datetime',
            'shopify_created_at' => 'datetime',
            'shopify_updated_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'confirmation_acted_at' => 'datetime',
            'postponed_until' => 'datetime',
            'assigned_at' => 'datetime',
            'delivery_taken_at' => 'datetime',
            'delivery_postponed_until' => 'datetime',
            'delivered_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'cod_remitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // T9 — normalized phone = client identity (Clients module, block alerts).
        static::saving(function (Order $order) {
            if ($order->isDirty('phone') || $order->isDirty('shipping_address') || $order->phone_key === null) {
                $order->phone_key = ClientService::key($order->phone ?: ($order->shipping_address['phone'] ?? null));
            }
            if (! $order->company_id) {
                $shopCompany = $order->shopify_shop_id
                    ? ShopifyShop::query()->whereKey($order->shopify_shop_id)->value('company_id')
                    : null;
                $order->company_id = $shopCompany ?: Company::default()->id;
            }
            if ($order->financial_status === 'paid' && $order->total_outstanding === null && (float) $order->amount_paid <= 0) {
                $order->amount_paid = $order->total_price;
                $order->total_outstanding = 0;
            }
            $watch = ['total_price', 'amount_paid', 'total_outstanding', 'financial_status', 'discount_total', 'line_items', 'shopify_refunds'];
            if ($order->amount_due === null || $order->isDirty($watch)) {
                $due = app(AmountDue::class)->calculate($order);
                if ($order->amount_due === null || abs((float) $order->amount_due - $due) >= 0.009) {
                    $order->amount_due = $due;
                }
            }
        });

        static::saved(function (Order $order) {
            if ($order->wasRecentlyCreated || ! $order->wasChanged('amount_due')) {
                return;
            }
            if (! $order->hasCarrierParcel()) {
                return;
            }
            if (! $order->amount_due_stale) {
                $order->forceFill(['amount_due_stale' => true])->saveQuietly();
            }
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'kind' => 'expedition',
                'status_code' => 'amount_due_changed',
                'status_name' => 'Montant à encaisser',
                'status_color' => '#d97706',
                'note' => 'Montant à encaisser modifié après l’envoi — vérifier le colis',
                'data' => ['amount_due' => (float) $order->amount_due],
            ]);
        });

        // Manual orders get a readable number (Shopify orders keep theirs).
        static::created(function (Order $order) {
            if (! $order->order_number && ! $order->name) {
                $order->order_number = (string) (1000 + $order->id);
                $order->name = 'CMD-'.$order->order_number;
                $order->saveQuietly();
            }
        });
    }

    /**
     * Maps the simple form fields used by the Commandes screens (and seeders/tests) onto the
     * Shopify-compatible columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributesFromForm(array $data, ?self $existing = null): array
    {
        $out = [];
        $map = [
            'customer_name' => 'customer_name',
            'customer_phone' => 'phone',
            'email' => 'email',
            'amount' => 'total_price',
            'note' => 'note',
            'source' => 'source',
            'carrier' => 'carrier',
            'assigned_user_id' => 'assigned_user_id',
            'reference' => 'name',
        ];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $data)) {
                $out[$to] = $data[$from];
            }
        }
        if (array_key_exists('payment_method', $data)) {
            $out['financial_status'] = $data['payment_method'] === 'paye' ? 'paid' : 'pending';
        }
        if (array_key_exists('city', $data) || array_key_exists('address', $data)) {
            $address = $existing?->shipping_address ?? [];
            if (array_key_exists('city', $data)) {
                $address['city'] = $data['city'];
            }
            if (array_key_exists('address', $data)) {
                $address['address1'] = $data['address'];
            }
            $out['shipping_address'] = $address;
        }
        if (array_key_exists('product_name', $data) || array_key_exists('quantity', $data) || array_key_exists('product_image', $data)) {
            $items = $existing?->line_items ?? [];
            $first = $items[0] ?? [];
            if (array_key_exists('product_name', $data)) {
                $first['title'] = $data['product_name'];
            }
            if (array_key_exists('quantity', $data)) {
                $first['quantity'] = (int) ($data['quantity'] ?: 1);
            }
            if (array_key_exists('product_image', $data)) {
                $first['image'] = $data['product_image'];
            }
            $first['quantity'] ??= 1;
            $items[0] = $first;
            $out['line_items'] = $items;
        }

        return $out;
    }

    /* ----------------------------------------------------------------- relations */

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

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** Configurable status referenced by code (includes inactive statuses for old orders). */
    public function deliveryStatus(): BelongsTo
    {
        return $this->belongsTo(DeliveryStatus::class, 'delivery_status', 'code');
    }

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(OrderCall::class)->latest('called_at')->latest('id');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class)->latest('id');
    }

    public function speedafShipments(): HasMany
    {
        return $this->hasMany(SpeedafShipment::class)->orderByDesc('id');
    }

    public function ozonShipments(): HasMany
    {
        return $this->hasMany(OzonShipment::class)->orderByDesc('id');
    }

    public function siftShipments(): HasMany
    {
        return $this->hasMany(SiftShipment::class)->orderByDesc('id');
    }

    /** Current Sift.ma parcel (not cancelled, not hidden), loaded relation first. */
    public function currentSiftShipment(): ?SiftShipment
    {
        $list = $this->relationLoaded('siftShipments') ? $this->siftShipments : $this->siftShipments()->get();

        return $list->first(fn (SiftShipment $s) => $s->isActive());
    }

    /** Current Speedaf waybill (latest not cancelled), from the loaded relation when available. */
    public function currentSpeedafShipment(): ?SpeedafShipment
    {
        $list = $this->relationLoaded('speedafShipments')
            ? $this->speedafShipments
            : $this->speedafShipments()->get();

        return $list->first(fn (SpeedafShipment $s) => $s->isActive());
    }

    /** Current Ozon Express parcel of the order itself (not an SAV exchange), loaded relation first. */
    public function currentOzonShipment(): ?OzonShipment
    {
        $list = $this->relationLoaded('ozonShipments') ? $this->ozonShipments : $this->ozonShipments()->get();

        return $list->first(fn (OzonShipment $s) => $s->isActive() && ! $s->sav_request_id);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(OrderFulfillment::class)->orderByDesc('id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function hasCarrierParcel(): bool
    {
        return $this->speedafShipments()->exists()
            || $this->ozonShipments()->exists()
            || $this->siftShipments()->exists();
    }

    public function amountDue(): float
    {
        if ($this->amount_due !== null && $this->amount_due !== '') {
            return round((float) $this->amount_due, 2);
        }

        return app(AmountDue::class)->calculate($this);
    }

    /* ----------------------------------------------------------------- display helpers */

    public function reference(): string
    {
        return (string) ($this->name ?: ($this->order_number ? '#'.$this->order_number : 'CMD-'.$this->id));
    }

    public function productName(): ?string
    {
        $items = is_array($this->line_items) ? $this->line_items : [];
        if ($items === []) {
            return null;
        }
        $first = $items[0]['title'] ?? $items[0]['name'] ?? null;
        if (count($items) > 1) {
            return trim(($first ?? 'Article').' + '.(count($items) - 1).' autre(s)');
        }

        return $first;
    }

    public function productImage(): ?string
    {
        $item = (is_array($this->line_items) ? $this->line_items : [])[0] ?? [];

        return $item['image'] ?? data_get($item, 'image.src');
    }

    public function itemsQuantity(): int
    {
        $items = is_array($this->line_items) ? $this->line_items : [];
        $qty = array_sum(array_map(fn ($i) => (int) ($i['quantity'] ?? 1), $items));

        return max(1, $qty);
    }

    public function paymentMethod(): string
    {
        $due = $this->amountDue();
        $total = round((float) $this->total_price, 2);
        if ($due <= 0.009) {
            return 'paye';
        }
        if ((float) $this->amount_paid > 0.009 && $due + 0.009 < $total) {
            return 'partial';
        }

        return 'cod';
    }

    /** Brahim's labels: Payée en ligne, Partiellement payée, Paiement à la livraison. */
    public function paymentLabel(): string
    {
        return match ($this->paymentMethod()) {
            'paye' => 'Payée en ligne',
            'partial' => 'Partiellement payée',
            default => 'Paiement à la livraison',
        };
    }

    public function isCod(): bool
    {
        return $this->amountDue() > 0.009;
    }

    public function sourceLabel(): ?string
    {
        if ($this->source) {
            return $this->source;
        }

        return $this->shopify_shop_id ? 'Shopify'.($this->shop?->shop_name ? ' · '.$this->shop->shop_name : '') : null;
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

    public function isConfirmed(): bool
    {
        return in_array($this->confirmation_status, ConfirmationStatus::codesOfType(ConfirmationStatus::TYPE_SUCCESS), true)
            || $this->confirmation_status === self::CONFIRMATION_CONFIRMED;
    }

    /** The configurable status of this order (uses the loaded relation when available). */
    public function deliveryStatusDefinition(): ?DeliveryStatus
    {
        if (! $this->delivery_status) {
            return null;
        }
        if (! $this->relationLoaded('deliveryStatus') || $this->deliveryStatus?->code !== $this->delivery_status) {
            $this->setRelation('deliveryStatus', DeliveryStatus::findByCode($this->delivery_status));
        }

        return $this->deliveryStatus;
    }

    public function deliveryCategory(): ?string
    {
        return $this->deliveryStatusDefinition()?->category;
    }

    public function isAwaitingAssignment(): bool
    {
        if (! $this->isConfirmed()) {
            return false;
        }
        if ($this->isActiveWithDriver()) {
            return false;
        }
        $category = $this->deliveryCategory();

        return $category === null || in_array($category, Catalog::ASSIGNABLE_CATEGORIES, true);
    }

    public function canBeAssigned(): bool
    {
        return $this->isAwaitingAssignment();
    }

    /**
     * Why this order cannot be (re)assigned to a local driver from Commandes, or null when it can.
     * Allowed: confirmed orders awaiting assignment, and orders already with a driver (re-assignment).
     */
    public function localAssignmentBlocker(?int $driverId = null): ?string
    {
        if (! $this->isConfirmed()) {
            return 'Commande non confirmée.';
        }
        if ($driverId && (int) $this->driver_id === $driverId && $this->isActiveWithDriver()) {
            return 'Déjà affectée à ce livreur.';
        }
        if ($this->currentSpeedafShipment()) {
            return 'Déjà envoyée à Speedaf (annulez le colis avant de l’affecter en local).';
        }
        if ($this->currentOzonShipment()) {
            return 'Déjà envoyée à Ozon Express.';
        }
        if ($this->currentSiftShipment()) {
            return 'Déjà envoyée à Sift.';
        }
        if ($this->isActiveWithDriver() || $this->isAwaitingAssignment()) {
            return null;
        }
        $status = $this->deliveryStatusDefinition();

        return 'Statut « '.($status?->name ?? $this->delivery_status).' » : affectation impossible.';
    }

    /**
     * Why this order cannot be sent to a carrier, or null when it can.
     * An active local driver already collects the COD — the parcel must not go out a second time.
     */
    public function carrierShipBlocker(): ?string
    {
        if ($this->isActiveWithDriver()) {
            return 'Commande affectée à la livraison locale ('.$this->driver?->name.') : retirez d’abord le livreur.';
        }

        return null;
    }

    public function isActiveWithDriver(): bool
    {
        return $this->driver_id
            && in_array($this->deliveryCategory(), Catalog::DRIVER_ACTIVE_CATEGORIES, true);
    }

    public function deliveryStatusLabel(): string
    {
        if (! $this->delivery_status) {
            return 'À attribuer';
        }

        return $this->deliveryStatusDefinition()?->name ?? $this->delivery_status;
    }

    public function deliveryStatusColor(): string
    {
        return $this->deliveryStatusDefinition()?->color ?? '#64748b';
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

    /* ----------------------------------------------------------------- scopes */

    public function scopeInDeliveryCategories(Builder $query, array $categories): Builder
    {
        return $query->whereIn('delivery_status', DeliveryStatus::codesForCategories($categories) ?: ['__none__']);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereIn('confirmation_status', ConfirmationStatus::codesOfType(ConfirmationStatus::TYPE_SUCCESS) ?: [self::CONFIRMATION_CONFIRMED]);
    }

    public function scopeAwaitingAssignment(Builder $query): Builder
    {
        $assignable = DeliveryStatus::codesForCategories(Catalog::ASSIGNABLE_CATEGORIES);
        $active = DeliveryStatus::codesForCategories(Catalog::DRIVER_ACTIVE_CATEGORIES);

        return $query->confirmed()
            ->where(fn ($q) => $q->whereNull('delivery_status')->orWhereIn('delivery_status', $assignable ?: ['__none__']))
            // "Attribuée" is avant_livraison too: exclude orders already with a driver in an active status.
            ->where(fn ($q) => $q->whereNull('driver_id')->orWhereNotIn('delivery_status', $active ?: ['__none__'])->orWhereNull('delivery_status'));
    }

    public function scopeActiveWithDriver(Builder $query): Builder
    {
        return $query->whereNotNull('driver_id')->inDeliveryCategories(Catalog::DRIVER_ACTIVE_CATEGORIES);
    }

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->where('driver_id', $driverId)->activeWithDriver();
    }
}

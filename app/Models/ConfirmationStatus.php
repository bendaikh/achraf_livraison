<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConfirmationStatus extends Model
{
    public const TYPE_OPEN = 'open';

    public const TYPE_WAITING = 'waiting';

    public const TYPE_SUCCESS = 'success';

    public const TYPE_CANCELLED = 'cancelled';

    public const TYPE_CUSTOM = 'custom';

    public const BEHAVIOR_DUE_QUEUE = 'due_queue';

    public const BEHAVIOR_FUTURE_ONLY = 'future_only';

    public const CATEGORY_WAITING = 'en_attente';

    public const CATEGORY_CONFIRMED = 'confirmee';

    public const CATEGORY_NO_ANSWER = 'pas_de_reponse';

    public const CATEGORY_RECALL = 'a_recontacter';

    public const CATEGORY_STOCK = 'probleme_stock';

    public const CATEGORY_CLIENT = 'attente_client';

    public const CATEGORY_FAILED = 'annulee_echec';

    public const CATEGORY_CUSTOM = 'personnalise';

    /** @var array<string, string> */
    public const CATEGORIES = [
        self::CATEGORY_WAITING => 'En attente',
        self::CATEGORY_CONFIRMED => 'Confirmée',
        self::CATEGORY_NO_ANSWER => 'Pas de réponse',
        self::CATEGORY_RECALL => 'À recontacter / programmée',
        self::CATEGORY_STOCK => 'Problème stock',
        self::CATEGORY_CLIENT => 'Attente client',
        self::CATEGORY_FAILED => 'Annulée / échec',
        self::CATEGORY_CUSTOM => 'Personnalisé',
    ];

    /** @var list<string> */
    public const SYSTEM_CODES = [
        Order::CONFIRMATION_TO_CONFIRM,
        Order::CONFIRMATION_POSTPONED,
        Order::CONFIRMATION_NO_ANSWER,
        Order::CONFIRMATION_CONFIRMED,
        Order::CONFIRMATION_CANCELLED,
    ];

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'color',
        'icon',
        'sort_order',
        'is_active',
        'type',
        'category',
        'filter_label',
        'show_in_filters',
        'is_terminal',
        'is_final',
        'is_default',
        'is_system',
        'queue_behavior',
        'stays_in_queue',
        'counts_as_confirmed',
        'counts_as_failure',
        'requires_recall_date',
        'requires_time',
        'requires_reason',
        'requires_comment',
        'requires_product',
        'reason_options',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'show_in_filters' => 'boolean',
            'is_terminal' => 'boolean',
            'is_final' => 'boolean',
            'is_default' => 'boolean',
            'is_system' => 'boolean',
            'stays_in_queue' => 'boolean',
            'counts_as_confirmed' => 'boolean',
            'counts_as_failure' => 'boolean',
            'requires_recall_date' => 'boolean',
            'requires_time' => 'boolean',
            'requires_reason' => 'boolean',
            'requires_comment' => 'boolean',
            'requires_product' => 'boolean',
            'reason_options' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ConfirmationStatus $status) {
            $status->applyDerivedFields();
        });
        static::saved(fn (ConfirmationStatus $status) => static::flushCache($status->company_id));
        static::deleted(fn (ConfirmationStatus $status) => static::flushCache($status->company_id));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForFilters(Builder $query): Builder
    {
        return $query->active()->where('show_in_filters', true)->ordered();
    }

    /**
     * type and queue_behavior stay in sync with the category and the behaviour flags
     * so existing queues, KPIs and automations keep working.
     */
    public function applyDerivedFields(): void
    {
        $this->is_terminal = (bool) $this->is_final;

        if ($this->counts_as_confirmed || $this->category === self::CATEGORY_CONFIRMED) {
            $this->type = self::TYPE_SUCCESS;
        } elseif ($this->counts_as_failure || $this->category === self::CATEGORY_FAILED) {
            $this->type = self::TYPE_CANCELLED;
        } elseif ($this->category === self::CATEGORY_WAITING || $this->is_default) {
            $this->type = self::TYPE_OPEN;
        } elseif (in_array($this->category, [
            self::CATEGORY_NO_ANSWER,
            self::CATEGORY_RECALL,
            self::CATEGORY_CLIENT,
            self::CATEGORY_STOCK,
        ], true)) {
            $this->type = self::TYPE_WAITING;
        } elseif ($this->category === self::CATEGORY_CUSTOM && $this->type === self::TYPE_WAITING) {
            // Legacy "waiting" with no queue behaviour is stored as personnalise and must stay waiting.
            $this->type = self::TYPE_WAITING;
        } else {
            $this->type = self::TYPE_CUSTOM;
        }

        if ($this->is_default || ($this->stays_in_queue && $this->category === self::CATEGORY_WAITING)) {
            $this->queue_behavior = self::BEHAVIOR_DUE_QUEUE;
        } elseif ($this->requires_recall_date && ! $this->stays_in_queue) {
            $this->queue_behavior = self::BEHAVIOR_FUTURE_ONLY;
        } elseif ($this->category === self::CATEGORY_RECALL && $this->requires_recall_date) {
            $this->queue_behavior = self::BEHAVIOR_FUTURE_ONLY;
        } else {
            $this->queue_behavior = null;
        }
    }

    public static function cacheKey(int $companyId): string
    {
        return 'confirmation_statuses.'.$companyId;
    }

    public static function flushCache(?int $companyId = null): void
    {
        Cache::forget('confirmation_statuses.all');
        if ($companyId) {
            Cache::forget(self::cacheKey($companyId));

            return;
        }
        if (! DB::getSchemaBuilder()->hasTable('companies')) {
            return;
        }
        foreach (DB::table('companies')->pluck('id') as $id) {
            Cache::forget(self::cacheKey((int) $id));
        }
    }

    public static function resolveCompanyId(?int $companyId = null): int
    {
        if ($companyId) {
            return $companyId;
        }
        $user = auth()->user();
        if ($user && method_exists($user, 'resolveCompanyId')) {
            return $user->resolveCompanyId();
        }

        return Company::default()->id;
    }

    /** @return \Illuminate\Support\Collection<int, self> */
    public static function cachedAll(?int $companyId = null)
    {
        $companyId = self::resolveCompanyId($companyId);

        return Cache::remember(self::cacheKey($companyId), 3600, function () use ($companyId) {
            return static::query()->forCompany($companyId)->ordered()->get();
        });
    }

    public static function findByCode(?string $code, ?int $companyId = null): ?self
    {
        if ($code === null || $code === '') {
            return null;
        }

        return static::cachedAll($companyId)->firstWhere('code', $code);
    }

    public static function requireByCode(string $code, ?int $companyId = null): self
    {
        $status = static::findByCode($code, $companyId);

        if (! $status) {
            throw new \RuntimeException("Statut de confirmation introuvable : {$code}");
        }

        return $status;
    }

    public static function defaultStatus(?int $companyId = null): ?self
    {
        $all = static::cachedAll($companyId);

        return $all->firstWhere('is_default', true)
            ?? $all->firstWhere('code', Order::CONFIRMATION_TO_CONFIRM);
    }

    public static function defaultCode(?int $companyId = null): string
    {
        return static::defaultStatus($companyId)?->code ?? Order::CONFIRMATION_TO_CONFIRM;
    }

    public static function labelFor(?string $code, ?int $companyId = null): string
    {
        return static::findByCode($code, $companyId)?->name ?? (string) $code;
    }

    public static function colorFor(?string $code, ?int $companyId = null): string
    {
        return static::findByCode($code, $companyId)?->color ?? '#64748b';
    }

    /** @return list<string> */
    public static function codesWithBehavior(string $behavior, ?int $companyId = null): array
    {
        if ($companyId) {
            return static::cachedAll($companyId)
                ->where('queue_behavior', $behavior)
                ->pluck('code')
                ->unique()
                ->values()
                ->all();
        }

        return static::query()->where('queue_behavior', $behavior)->pluck('code')->unique()->values()->all();
    }

    /** @return list<string> */
    public static function codesOfType(string $type, ?int $companyId = null): array
    {
        if ($companyId) {
            return static::cachedAll($companyId)->where('type', $type)->pluck('code')->unique()->values()->all();
        }

        return static::query()->where('type', $type)->pluck('code')->unique()->values()->all();
    }

    /** @return list<string> */
    public static function codesWithFlag(string $flag, ?int $companyId = null): array
    {
        if ($companyId) {
            return static::cachedAll($companyId)->where($flag, true)->pluck('code')->unique()->values()->all();
        }

        return static::query()->where($flag, true)->pluck('code')->unique()->values()->all();
    }

    /** @return list<string> */
    public static function codesOfCategory(string $category, ?int $companyId = null): array
    {
        if ($companyId) {
            return static::cachedAll($companyId)->where('category', $category)->pluck('code')->unique()->values()->all();
        }

        return static::query()->where('category', $category)->pluck('code')->unique()->values()->all();
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? (string) $this->category;
    }

    public function filterDisplayLabel(): string
    {
        return $this->filter_label ?: $this->name;
    }

    /** @return list<string> */
    public function flagSummary(): array
    {
        $flags = [];
        if ($this->stays_in_queue) {
            $flags[] = 'File';
        }
        if ($this->counts_as_confirmed) {
            $flags[] = 'Confirmée';
        }
        if ($this->counts_as_failure) {
            $flags[] = 'Échec';
        }
        if ($this->requires_recall_date) {
            $flags[] = 'Date';
        }
        if ($this->requires_time) {
            $flags[] = 'Heure';
        }
        if ($this->requires_reason) {
            $flags[] = 'Motif';
        }
        if ($this->requires_comment) {
            $flags[] = 'Commentaire';
        }
        if ($this->requires_product) {
            $flags[] = 'Produit';
        }
        if ($this->is_final) {
            $flags[] = 'Final';
        }

        return $flags;
    }

    public function toApiArray(int $ordersCount = 0, int $historyCount = 0): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'code' => $this->code,
            'color' => $this->color,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'type' => $this->type,
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'filter_label' => $this->filterDisplayLabel(),
            'show_in_filters' => $this->show_in_filters,
            'is_terminal' => $this->is_terminal,
            'is_final' => (bool) $this->is_final,
            'is_default' => $this->is_default,
            'is_system' => (bool) $this->is_system,
            'queue_behavior' => $this->queue_behavior,
            'stays_in_queue' => (bool) $this->stays_in_queue,
            'counts_as_confirmed' => (bool) $this->counts_as_confirmed,
            'counts_as_failure' => (bool) $this->counts_as_failure,
            'requires_recall_date' => (bool) $this->requires_recall_date,
            'requires_time' => (bool) $this->requires_time,
            'requires_reason' => (bool) $this->requires_reason,
            'requires_comment' => (bool) $this->requires_comment,
            'requires_product' => (bool) $this->requires_product,
            'reason_options' => array_values($this->reason_options ?? []),
            'flags_summary' => $this->flagSummary(),
            'orders_count' => $ordersCount,
            'history_count' => $historyCount,
            'usage_count' => $ordersCount + $historyCount,
            'can_delete' => ! $this->is_system && ($ordersCount + $historyCount) === 0,
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ConfirmationStatus extends Model
{
    public const TYPE_OPEN = 'open';

    public const TYPE_WAITING = 'waiting';

    public const TYPE_SUCCESS = 'success';

    public const TYPE_CANCELLED = 'cancelled';

    public const TYPE_CUSTOM = 'custom';

    public const BEHAVIOR_DUE_QUEUE = 'due_queue';

    public const BEHAVIOR_FUTURE_ONLY = 'future_only';

    public const CACHE_KEY = 'confirmation_statuses.all';

    protected $fillable = [
        'name',
        'code',
        'color',
        'icon',
        'sort_order',
        'is_active',
        'type',
        'filter_label',
        'show_in_filters',
        'is_terminal',
        'is_default',
        'queue_behavior',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'show_in_filters' => 'boolean',
            'is_terminal' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function scopeForFilters(Builder $query): Builder
    {
        return $query->active()->where('show_in_filters', true)->ordered();
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return \Illuminate\Support\Collection<int, self> */
    public static function cachedAll()
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            return static::query()->ordered()->get();
        });
    }

    public static function findByCode(?string $code): ?self
    {
        if ($code === null || $code === '') {
            return null;
        }

        return static::cachedAll()->firstWhere('code', $code);
    }

    public static function requireByCode(string $code): self
    {
        $status = static::findByCode($code);

        if (! $status) {
            $status = static::query()->where('code', $code)->first();
        }

        if (! $status) {
            throw new \RuntimeException("Statut de confirmation introuvable : {$code}");
        }

        return $status;
    }

    public static function defaultStatus(): ?self
    {
        return static::cachedAll()->firstWhere('is_default', true)
            ?? static::cachedAll()->firstWhere('code', 'to_confirm');
    }

    public static function defaultCode(): string
    {
        return static::defaultStatus()?->code ?? 'to_confirm';
    }

    public static function labelFor(?string $code): string
    {
        $status = static::findByCode($code);

        return $status?->name ?? (string) $code;
    }

    public static function colorFor(?string $code): string
    {
        return static::findByCode($code)?->color ?? '#64748b';
    }

    /** @return list<string> */
    public static function codesWithBehavior(string $behavior): array
    {
        return static::cachedAll()
            ->where('queue_behavior', $behavior)
            ->pluck('code')
            ->values()
            ->all();
    }

    public function filterDisplayLabel(): string
    {
        return $this->filter_label ?: $this->name;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'color' => $this->color,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'type' => $this->type,
            'filter_label' => $this->filterDisplayLabel(),
            'show_in_filters' => $this->show_in_filters,
            'is_terminal' => $this->is_terminal,
            'is_default' => $this->is_default,
            'queue_behavior' => $this->queue_behavior,
        ];
    }
}

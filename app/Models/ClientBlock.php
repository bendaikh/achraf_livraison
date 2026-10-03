<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Block history for a client (phone_key). Active = unblocked_at null. Never deleted. */
class ClientBlock extends Model
{
    protected $fillable = ['company_id', 'phone_key', 'customer_name', 'reason', 'comment', 'blocked_by', 'blocked_at', 'unblocked_by', 'unblocked_at', 'unblock_reason'];

    protected function casts(): array
    {
        return ['blocked_at' => 'datetime', 'unblocked_at' => 'datetime'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('unblocked_at');
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function unblocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unblocked_by');
    }

    /** Active block for a phone key (cached per request; few rows expected). */
    public static function activeFor(?string $phoneKey): ?self
    {
        if (! $phoneKey) {
            return null;
        }
        // Container-level cache (one query per request / test app).
        if (! app()->bound('client_blocks.active')) {
            app()->instance('client_blocks.active', static::query()->active()->with('blocker:id,name')->get()->keyBy('phone_key')->all());
        }

        return app('client_blocks.active')[$phoneKey] ?? null;
    }

    public static function flushCache(): void
    {
        app()->forgetInstance('client_blocks.active');
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'comment' => $this->comment,
            'blocked_at' => $this->blocked_at?->toIso8601String(),
            'blocked_by_name' => $this->blocker?->name,
            'unblocked_at' => $this->unblocked_at?->toIso8601String(),
            'unblocked_by_name' => $this->unblocker?->name,
            'unblock_reason' => $this->unblock_reason,
            'active' => $this->unblocked_at === null,
        ];
    }
}

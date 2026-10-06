<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationQueueJob extends Model
{
    public const TYPE_WAIT = 'wait';

    public const TYPE_RETRY = 'retry';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'automation_run_id', 'type', 'step_key', 'status',
        'available_at', 'attempts', 'max_attempts', 'payload', 'last_error', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
            'payload' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    public function scopeDue(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING)
            ->where(fn ($w) => $w->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AutomationRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'company_id', 'automation_id', 'automation_version', 'status', 'simulation',
        'trigger_type', 'trigger_payload', 'subject_type', 'subject_id', 'idempotency_key',
        'context', 'current_step_key', 'error_message', 'started_at', 'finished_at', 'resume_at',
    ];

    protected function casts(): array
    {
        return [
            'simulation' => 'boolean',
            'trigger_payload' => 'array',
            'context' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'resume_at' => 'datetime',
            'automation_version' => 'integer',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationRunStep::class)->orderBy('id');
    }

    public function queueJobs(): HasMany
    {
        return $this->hasMany(AutomationQueueJob::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForCompany(Builder $q, int $companyId): Builder
    {
        return $q->where('company_id', $companyId);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_SKIPPED,
        ], true);
    }
}

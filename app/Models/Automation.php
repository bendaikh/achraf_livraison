<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Automation extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ERROR = 'error';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Brouillon',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_PAUSED => 'En pause',
        self::STATUS_ERROR => 'Erreur',
    ];

    protected $fillable = [
        'company_id', 'name', 'status', 'trigger_type', 'trigger_config', 'definition', 'version',
        'created_by', 'updated_by', 'last_run_at', 'runs_count', 'success_count', 'error_count', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'definition' => 'array',
            'last_run_at' => 'datetime',
            'archived_at' => 'datetime',
            'version' => 'integer',
            'runs_count' => 'integer',
            'success_count' => 'integer',
            'error_count' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationVersion::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function scopeForCompany(Builder $q, int $companyId): Builder
    {
        return $q->where('company_id', $companyId);
    }

    public function scopeNotArchived(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE)->whereNull('archived_at');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->archived_at === null;
    }

    /** Integration keys referenced by action steps in the definition. */
    public function usedIntegrations(): array
    {
        $keys = [];
        foreach ((array) ($this->definition['steps'] ?? []) as $step) {
            if (($step['type'] ?? '') !== 'action') {
                continue;
            }
            $action = (string) ($step['action'] ?? '');
            $prefix = explode('.', $action, 2)[0] ?? '';
            if ($prefix && $prefix !== 'internal') {
                $keys[$prefix] = true;
            }
        }

        return array_keys($keys);
    }
}

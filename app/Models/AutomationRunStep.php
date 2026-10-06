<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRunStep extends Model
{
    protected $fillable = [
        'company_id', 'automation_run_id', 'step_key', 'type', 'status',
        'input_json', 'output_json', 'error', 'attempt', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input_json' => 'array',
            'output_json' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'attempt' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }
}

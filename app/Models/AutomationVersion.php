<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationVersion extends Model
{
    protected $fillable = [
        'company_id', 'automation_id', 'version', 'definition', 'trigger_config', 'trigger_type',
        'name', 'changed_by', 'change_note',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'trigger_config' => 'array',
            'version' => 'integer',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

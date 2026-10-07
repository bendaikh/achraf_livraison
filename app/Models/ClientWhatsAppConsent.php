<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientWhatsAppConsent extends Model
{
    protected $table = 'client_whatsapp_consents';

    public const STATUS_ALLOWED = 'allowed';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_UNKNOWN = 'unknown';

    protected $fillable = [
        'company_id', 'phone_key', 'status', 'source',
        'consented_at', 'refused_at', 'updated_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'refused_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function toApiArray(): array
    {
        return [
            'phone_key' => $this->phone_key,
            'status' => $this->status,
            'source' => $this->source,
            'consented_at' => $this->consented_at?->toIso8601String(),
            'refused_at' => $this->refused_at?->toIso8601String(),
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppAccount extends Model
{
    protected $table = 'whatsapp_accounts';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'company_id',
        'name',
        'phone_number',
        'display_phone_number',
        'phone_number_id',
        'waba_id',
        'business_portfolio_id',
        'access_token',
        'status',
        'is_active',
        'connection_method',
        'meta_error',
        'last_synced_at',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class, 'whatsapp_account_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_account_id');
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED
            && $this->is_active
            && filled($this->access_token)
            && filled($this->phone_number_id);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone_number' => $this->phone_number,
            'display_phone_number' => $this->display_phone_number,
            'phone_number_id' => $this->phone_number_id,
            'waba_id' => $this->waba_id,
            'business_portfolio_id' => $this->business_portfolio_id,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'connection_method' => $this->connection_method,
            'meta_error' => $this->meta_error,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'is_connected' => $this->isConnected(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

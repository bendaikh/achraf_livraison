<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'company_id',
        'whatsapp_account_id',
        'waba_id',
        'meta_template_id',
        'name',
        'language',
        'category',
        'status',
        'body_text',
        'header_text',
        'footer_text',
        'variables_count',
        'components',
        'buttons',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'buttons' => 'array',
            'variables_count' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function isApproved(): bool
    {
        return strtoupper((string) $this->status) === 'APPROVED';
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'whatsapp_account_id' => $this->whatsapp_account_id,
            'waba_id' => $this->waba_id,
            'meta_template_id' => $this->meta_template_id,
            'name' => $this->name,
            'language' => $this->language,
            'category' => $this->category,
            'status' => $this->status,
            'body_text' => $this->body_text,
            'header_text' => $this->header_text,
            'footer_text' => $this->footer_text,
            'variables_count' => $this->variables_count,
            'components' => $this->components,
            'buttons' => $this->buttons,
            'is_approved' => $this->isApproved(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'account' => $this->account ? [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'display_phone_number' => $this->account->display_phone_number,
            ] : null,
        ];
    }
}

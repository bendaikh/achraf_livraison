<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppCampaignRecipient extends Model
{
    protected $table = 'whatsapp_campaign_recipients';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXCLUDED = 'excluded';

    protected $fillable = [
        'company_id', 'whatsapp_campaign_id', 'phone_key', 'customer_name', 'phone',
        'status', 'exclude_reason', 'resolved_variables', 'preview_body',
        'whatsapp_message_id', 'wa_message_id', 'attempts',
        'queued_at', 'sent_at', 'delivered_at', 'read_at', 'failed_at',
        'error_code', 'error_message', 'retriable',
    ];

    protected function casts(): array
    {
        return [
            'resolved_variables' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'retriable' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'whatsapp_campaign_id', 'id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class, 'whatsapp_message_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'phone_key' => $this->phone_key,
            'customer_name' => $this->customer_name,
            'phone' => $this->phone,
            'status' => $this->status,
            'exclude_reason' => $this->exclude_reason,
            'preview_body' => $this->preview_body,
            'wa_message_id' => $this->wa_message_id,
            'attempts' => (int) $this->attempts,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'retriable' => (bool) $this->retriable,
            'campaign_id' => $this->whatsapp_campaign_id,
        ];
    }
}

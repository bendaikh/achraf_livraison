<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'whatsapp_conversation_id',
        'whatsapp_account_id',
        'direction',
        'type',
        'body',
        'media_id',
        'media_mime',
        'media_filename',
        'media_path',
        'template_name',
        'template_language',
        'template_components',
        'latitude',
        'longitude',
        'location_name',
        'wa_message_id',
        'status',
        'error_code',
        'error_message',
        'sent_by_user_id',
        'meta_timestamp',
        'status_timestamp',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'template_components' => 'array',
            'raw_payload' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'meta_timestamp' => 'datetime',
            'status_timestamp' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whatsapp_conversation_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'type' => $this->type,
            'body' => $this->body,
            'media_id' => $this->media_id,
            'media_mime' => $this->media_mime,
            'media_filename' => $this->media_filename,
            'has_media' => filled($this->media_path) || filled($this->media_id),
            'template_name' => $this->template_name,
            'template_language' => $this->template_language,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'location_name' => $this->location_name,
            'wa_message_id' => $this->wa_message_id,
            'status' => $this->status,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'sent_by' => $this->sentBy ? [
                'id' => $this->sentBy->id,
                'name' => $this->sentBy->name,
            ] : null,
            'meta_timestamp' => $this->meta_timestamp?->toIso8601String(),
            'status_timestamp' => $this->status_timestamp?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

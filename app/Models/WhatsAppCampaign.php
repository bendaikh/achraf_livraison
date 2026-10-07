<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppCampaign extends Model
{
    protected $table = 'whatsapp_campaigns';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ERROR = 'error';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'company_id', 'name', 'description', 'status',
        'whatsapp_account_id', 'whatsapp_template_id',
        'audience_definition', 'variable_mapping', 'manual_phone_keys', 'exclusion_phone_keys',
        'stats', 'scheduled_at', 'started_at', 'finished_at', 'paused_at', 'archived_at',
        'error_message',
        'recipients_count', 'excluded_count', 'sent_count', 'delivered_count',
        'read_count', 'failed_count', 'pending_count',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'audience_definition' => 'array',
            'variable_mapping' => 'array',
            'manual_phone_keys' => 'array',
            'exclusion_phone_keys' => 'array',
            'stats' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'paused_at' => 'datetime',
            'archived_at' => 'datetime',
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'whatsapp_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(WhatsAppCampaignRecipient::class, 'whatsapp_campaign_id');
    }

    public function exclusionReasons(): HasMany
    {
        return $this->hasMany(WhatsAppCampaignExclusionReason::class, 'whatsapp_campaign_id');
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at')->where('status', '!=', self::STATUS_ARCHIVED);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true)
            && $this->archived_at === null;
    }

    public function canSend(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true)
            && $this->archived_at === null;
    }

    public function toApiArray(bool $detailed = false): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'whatsapp_account_id' => $this->whatsapp_account_id,
            'whatsapp_template_id' => $this->whatsapp_template_id,
            'account' => $this->relationLoaded('account') && $this->account ? [
                'id' => $this->account->id,
                'display_name' => $this->account->name
                    ?: ($this->account->display_phone_number ?: $this->account->phone_number),
                'phone_number' => $this->account->display_phone_number ?: $this->account->phone_number,
            ] : null,
            'template' => $this->relationLoaded('template') && $this->template ? [
                'id' => $this->template->id,
                'name' => $this->template->name,
                'language' => $this->template->language,
                'status' => $this->template->status,
                'body_text' => $this->template->body_text,
                'variables_count' => $this->template->variables_count,
            ] : null,
            'creator' => $this->relationLoaded('creator') && $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'audience_definition' => $this->audience_definition,
            'variable_mapping' => $this->variable_mapping,
            'manual_phone_keys' => $this->manual_phone_keys ?? [],
            'exclusion_phone_keys' => $this->exclusion_phone_keys ?? [],
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'recipients_count' => (int) $this->recipients_count,
            'excluded_count' => (int) $this->excluded_count,
            'sent_count' => (int) $this->sent_count,
            'delivered_count' => (int) $this->delivered_count,
            'read_count' => (int) $this->read_count,
            'failed_count' => (int) $this->failed_count,
            'pending_count' => (int) $this->pending_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'editable' => $this->isEditable(),
        ];

        if ($detailed) {
            $data['stats'] = $this->stats ?? [];
            $data['exclusion_reasons'] = $this->relationLoaded('exclusionReasons')
                ? $this->exclusionReasons->map(fn ($r) => [
                    'reason' => $r->reason,
                    'count' => (int) $r->count,
                ])->values()->all()
                : [];
        }

        return $data;
    }
}

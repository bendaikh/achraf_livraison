<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppCampaignExclusionReason extends Model
{
    protected $table = 'whatsapp_campaign_exclusion_reasons';

    protected $fillable = [
        'company_id', 'whatsapp_campaign_id', 'reason', 'count',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'whatsapp_campaign_id', 'id');
    }
}

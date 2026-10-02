<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeedafWebhookEvent extends Model
{
    protected $fillable = ['company_id', 'event_id', 'mail_no', 'payload', 'processed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}

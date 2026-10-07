<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientTagAssignment extends Model
{
    protected $fillable = [
        'company_id', 'client_tag_id', 'phone_key', 'assigned_by',
    ];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(ClientTag::class, 'client_tag_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

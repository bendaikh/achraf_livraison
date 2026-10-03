<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Manual client group (T9). Members are phone keys. */
class ClientGroup extends Model
{
    protected $fillable = ['company_id', 'name', 'color', 'description', 'created_by'];

    public function members(): HasMany
    {
        return $this->hasMany(ClientGroupMember::class);
    }
}

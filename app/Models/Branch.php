<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = ['tenant_id', 'facility_id', 'name', 'timezone', 'address'];

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function courts(): HasMany
    {
        return $this->hasMany(Court::class);
    }
}

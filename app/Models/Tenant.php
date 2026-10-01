<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'name', 'slug', 'timezone', 'country_code', 'is_active',
        'subscription_plan', 'subscription_status', 'subscription_amount', 'subscription_renews_at',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'subscription_amount' => 'decimal:2',
            'subscription_renews_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachProfile extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'name', 'email', 'phone', 'bio', 'hourly_rate', 'is_active'];

    protected function casts(): array
    {
        return ['hourly_rate' => 'decimal:2', 'is_active' => 'boolean'];
    }
}

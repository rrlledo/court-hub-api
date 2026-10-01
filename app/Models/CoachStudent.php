<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachStudent extends Model
{
    protected $fillable = ['tenant_id', 'coach_profile_id', 'user_id', 'revenue_share_percent', 'notes'];

    protected function casts(): array
    {
        return ['revenue_share_percent' => 'decimal:2'];
    }
}

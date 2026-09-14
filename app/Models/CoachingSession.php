<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachingSession extends Model
{
    protected $fillable = ['tenant_id', 'coach_profile_id', 'user_id', 'court_id', 'starts_at', 'ends_at', 'amount', 'status', 'notes'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'amount' => 'decimal:2'];
    }
}

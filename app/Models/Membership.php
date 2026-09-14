<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    protected $fillable = ['tenant_id', 'membership_plan_id', 'user_id', 'starts_on', 'ends_on', 'status', 'auto_renew', 'frozen_at', 'remaining_sessions'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'auto_renew' => 'boolean', 'frozen_at' => 'datetime'];
    }
}

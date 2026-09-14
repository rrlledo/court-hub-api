<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachAvailability extends Model
{
    protected $fillable = ['tenant_id', 'coach_profile_id', 'day_of_week', 'starts_at', 'ends_at'];
}

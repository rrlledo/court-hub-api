<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringReservation extends Model
{
    protected $fillable = ['tenant_id', 'court_id', 'user_id', 'day_of_week', 'starts_at', 'duration_minutes', 'starts_on', 'ends_on', 'status'];
}

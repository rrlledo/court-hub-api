<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $fillable = ['tenant_id', 'booking_id', 'user_id', 'amount', 'status', 'reason'];
}

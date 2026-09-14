<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipSessionUsage extends Model
{
    protected $fillable = ['tenant_id', 'membership_id', 'booking_id', 'used_by', 'used_at', 'notes'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}

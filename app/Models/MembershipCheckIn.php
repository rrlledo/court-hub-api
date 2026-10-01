<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipCheckIn extends Model
{
    protected $fillable = ['tenant_id', 'membership_id', 'user_id', 'checked_in_by', 'method', 'checked_in_at'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }
}

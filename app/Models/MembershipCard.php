<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipCard extends Model
{
    protected $fillable = ['tenant_id', 'membership_id', 'card_number', 'qr_code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

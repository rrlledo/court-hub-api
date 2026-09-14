<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipPlan extends Model
{
    protected $fillable = ['tenant_id', 'name', 'plan_type', 'billing_period', 'price', 'currency', 'duration_days', 'session_count', 'discount_percent', 'priority_booking', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'discount_percent' => 'decimal:2', 'priority_booking' => 'boolean', 'is_active' => 'boolean'];
    }
}

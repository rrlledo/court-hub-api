<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = ['tenant_id', 'booking_id', 'membership_id', 'user_id', 'reference', 'invoice_number', 'method', 'provider', 'provider_reference', 'status', 'paid_at', 'amount', 'currency', 'provider_payload'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'provider_payload' => 'array'];
    }
}

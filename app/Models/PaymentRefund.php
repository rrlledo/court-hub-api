<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRefund extends Model
{
    protected $fillable = ['tenant_id', 'payment_id', 'requested_by', 'amount', 'status', 'reason'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}

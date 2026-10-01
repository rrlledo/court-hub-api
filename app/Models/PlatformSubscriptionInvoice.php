<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSubscriptionInvoice extends Model
{
    protected $fillable = ['tenant_id', 'reference', 'status', 'amount', 'tax_amount', 'currency', 'due_on', 'paid_at', 'metadata'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'due_on' => 'date', 'paid_at' => 'datetime', 'metadata' => 'array'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rental extends Model
{
    protected $fillable = ['tenant_id', 'inventory_item_id', 'user_id', 'quantity', 'rented_at', 'due_at', 'returned_at', 'status', 'amount', 'deposit_amount', 'notes'];

    protected function casts(): array
    {
        return ['rented_at' => 'datetime', 'due_at' => 'datetime', 'returned_at' => 'datetime', 'amount' => 'decimal:2', 'deposit_amount' => 'decimal:2'];
    }
}

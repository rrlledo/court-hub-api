<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    protected $fillable = ['tenant_id', 'branch_id', 'name', 'sku', 'quantity_total', 'quantity_available', 'rental_price', 'deposit_amount', 'is_active'];

    protected function casts(): array
    {
        return ['rental_price' => 'decimal:2', 'deposit_amount' => 'decimal:2', 'is_active' => 'boolean'];
    }
}

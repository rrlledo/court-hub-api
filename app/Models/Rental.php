<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Rental extends Model
{
    public const STAFF_ROLES = ['court-owner', 'facility-manager', 'front-desk'];

    protected $fillable = ['tenant_id', 'inventory_item_id', 'user_id', 'quantity', 'rented_at', 'due_at', 'returned_at', 'status', 'amount', 'deposit_amount', 'notes'];

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where('tenant_id', $user->tenant_id);

        if (! $user->hasAnyRole(self::STAFF_ROLES)) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    protected function casts(): array
    {
        return ['rented_at' => 'datetime', 'due_at' => 'datetime', 'returned_at' => 'datetime', 'amount' => 'decimal:2', 'deposit_amount' => 'decimal:2'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    protected $fillable = ['tenant_id', 'court_id', 'user_id', 'reference', 'starts_at', 'ends_at', 'status', 'amount', 'currency', 'expires_at', 'notes', 'source', 'qr_code', 'parent_booking_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'expires_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

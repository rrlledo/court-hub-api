<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    protected $fillable = ['tenant_id', 'branch_id', 'name', 'format', 'starts_at', 'ends_at', 'entry_fee', 'capacity', 'status'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'entry_fee' => 'decimal:2'];
    }
}

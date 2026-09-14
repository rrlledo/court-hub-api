<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourtMaintenance extends Model
{
    protected $table = 'court_maintenance';

    protected $fillable = ['tenant_id', 'court_id', 'starts_at', 'ends_at', 'status', 'reason'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}

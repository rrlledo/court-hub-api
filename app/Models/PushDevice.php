<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushDevice extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'token_hash', 'token', 'platform', 'device_name', 'last_seen_at'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'last_seen_at' => 'datetime'];
    }
}

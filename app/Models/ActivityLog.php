<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = ['tenant_id', 'actor_id', 'subject_type', 'subject_id', 'event', 'properties', 'ip_address'];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }
}

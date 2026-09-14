<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'email_enabled', 'sms_enabled', 'push_enabled'];

    protected function casts(): array
    {
        return ['email_enabled' => 'boolean', 'sms_enabled' => 'boolean', 'push_enabled' => 'boolean'];
    }
}

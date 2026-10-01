<?php

namespace App\Models;

use App\Jobs\DeliverPushNotification;
use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'channel', 'type', 'title', 'message', 'data', 'read_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::created(function (UserNotification $notification): void {
            PushDevice::where('user_id', $notification->user_id)
                ->pluck('id')
                ->each(fn (int $deviceId) => DeliverPushNotification::dispatch($notification->id, $deviceId)->afterCommit());
        });
    }
}

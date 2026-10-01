<?php

namespace App\Jobs;

use App\Exceptions\InvalidPushDeviceToken;
use App\Models\NotificationPreference;
use App\Models\PushDevice;
use App\Models\UserNotification;
use App\Services\FcmPushDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $notificationId, public readonly int $deviceId) {}

    public function handle(FcmPushDelivery $delivery): void
    {
        $notification = UserNotification::find($this->notificationId);
        $device = PushDevice::find($this->deviceId);
        if (! $notification || ! $device || $notification->user_id !== $device->user_id) {
            return;
        }

        if (! NotificationPreference::where('user_id', $notification->user_id)->value('push_enabled')) {
            return;
        }

        try {
            $delivery->send($device, $notification);
        } catch (InvalidPushDeviceToken) {
            $device->delete();
        }
    }
}

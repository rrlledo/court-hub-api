<?php

namespace Tests\Integration;

use App\Jobs\DeliverPushNotification;
use App\Models\NotificationPreference;
use App\Models\PushDevice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\FcmPushDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_can_register_and_remove_a_push_device(): void
    {
        $user = $this->player();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/push/devices', [
            'token' => 'fcm-registration-token',
            'platform' => 'android',
            'device_name' => 'Pixel',
        ])->assertCreated()->assertJsonPath('data.platform', 'android');

        $this->assertDatabaseHas('push_devices', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'fcm-registration-token'),
            'platform' => 'android',
        ]);
        $this->assertTrue((bool) NotificationPreference::where('user_id', $user->id)->value('push_enabled'));

        $this->deleteJson('/api/v1/push/devices', ['token' => 'fcm-registration-token'])->assertNoContent();
        $this->assertDatabaseMissing('push_devices', ['user_id' => $user->id]);
    }

    public function test_new_in_app_notification_queues_delivery_for_registered_device(): void
    {
        Queue::fake();
        $user = $this->player();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/push/devices', ['token' => 'token-for-delivery', 'platform' => 'ios'])->assertCreated();

        $notification = UserNotification::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'channel' => 'in_app',
            'type' => 'booking_confirmed',
            'title' => 'Booking confirmed',
            'message' => 'Your court is ready.',
        ]);

        Queue::assertPushed(DeliverPushNotification::class, fn (DeliverPushNotification $job) => $job->notificationId === $notification->id);
    }

    public function test_delivery_job_sends_to_an_enabled_registered_device(): void
    {
        Queue::fake();
        $user = $this->player();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/push/devices', ['token' => 'token-for-send', 'platform' => 'android'])->assertCreated();
        $device = PushDevice::firstOrFail();
        $notification = UserNotification::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'channel' => 'in_app',
            'type' => 'membership_active',
            'title' => 'Membership active',
            'message' => 'Welcome back.',
        ]);
        $delivery = Mockery::mock(FcmPushDelivery::class);
        $delivery->shouldReceive('send')->once()->withArgs(fn (PushDevice $sentDevice, UserNotification $sentNotification) => $sentDevice->is($device) && $sentNotification->is($notification));

        (new DeliverPushNotification($notification->id, $device->id))->handle($delivery);
    }

    private function player(): User
    {
        $tenant = Tenant::create(['name' => 'Push Sports', 'slug' => 'push-sports']);
        Role::findOrCreate('player', 'web');
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('player');

        return $user;
    }
}

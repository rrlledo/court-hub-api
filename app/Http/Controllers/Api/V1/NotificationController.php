<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\CreateUserNotification;
use App\Models\NotificationPreference;
use App\Models\PushDevice;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return UserNotification::where('user_id', $request->user()->id)->latest()->paginate();
    }

    public function markRead(Request $request, int $notification)
    {
        $model = UserNotification::where('user_id', $request->user()->id)->findOrFail($notification);
        $model->update(['read_at' => $model->read_at ?? now()]);

        return response()->json(['data' => $model]);
    }

    public function markAllRead(Request $request)
    {
        $count = UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['updated' => $count]]);
    }

    public function preferences(Request $request)
    {
        $data = $request->validate(['email_enabled' => ['required', 'boolean'], 'sms_enabled' => ['required', 'boolean'], 'push_enabled' => ['required', 'boolean']]);
        $preference = NotificationPreference::updateOrCreate(['user_id' => $request->user()->id], $data);

        return response()->json(['data' => $preference]);
    }

    public function registerDevice(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['required', 'in:android,ios'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);
        $user = $request->user();
        $device = PushDevice::updateOrCreate(
            ['token_hash' => hash('sha256', $data['token'])],
            [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'token' => $data['token'],
                'platform' => $data['platform'],
                'device_name' => $data['device_name'] ?? null,
                'last_seen_at' => now(),
            ],
        );
        NotificationPreference::firstOrCreate(['user_id' => $user->id])->update(['push_enabled' => true]);

        return response()->json(['data' => ['id' => $device->id, 'platform' => $device->platform]], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function unregisterDevice(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        PushDevice::where('user_id', $request->user()->id)
            ->where('token_hash', hash('sha256', $data['token']))
            ->delete();

        return response()->noContent();
    }

    public function dispatchTest(Request $request)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'message' => ['required', 'string', 'max:1000']]);
        CreateUserNotification::dispatch($request->user()->tenant_id, $request->user()->id, $data['title'], $data['message'], 'test');

        return response()->json(['data' => ['queued' => true]], 202);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\CreateUserNotification;
use App\Models\NotificationPreference;
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

    public function dispatchTest(Request $request)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'message' => ['required', 'string', 'max:1000']]);
        CreateUserNotification::dispatch($request->user()->tenant_id, $request->user()->id, $data['title'], $data['message'], 'test');

        return response()->json(['data' => ['queued' => true]], 202);
    }
}

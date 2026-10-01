<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CoachAvailability;
use App\Models\CoachingSession;
use App\Models\CoachProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoachWorkspaceController extends Controller
{
    public function dashboard(Request $request)
    {
        $coach = $this->coach($request);

        return response()->json(['data' => [
            'profile' => $coach,
            'availability' => CoachAvailability::where('coach_profile_id', $coach->id)->orderBy('day_of_week')->orderBy('starts_at')->get(),
            'sessions' => CoachingSession::where('coach_profile_id', $coach->id)->latest('starts_at')->limit(50)->get()->map(fn (CoachingSession $session) => $this->sessionData($session)),
        ]]);
    }

    public function replaceAvailability(Request $request)
    {
        $coach = $this->coach($request);
        $data = $request->validate(['availability' => ['required', 'array'], 'availability.*.day_of_week' => ['required', 'integer', 'between:0,6'], 'availability.*.starts_at' => ['required', 'date_format:H:i'], 'availability.*.ends_at' => ['required', 'date_format:H:i']]);
        foreach ($data['availability'] as $slot) {
            abort_if($slot['ends_at'] <= $slot['starts_at'], 422, 'Availability end time must be later than its start time.');
        }
        DB::transaction(function () use ($request, $coach, $data) {
            CoachAvailability::where('coach_profile_id', $coach->id)->delete();
            foreach ($data['availability'] as $slot) {
                CoachAvailability::create($slot + ['tenant_id' => $request->user()->tenant_id, 'coach_profile_id' => $coach->id]);
            }
        });

        return $this->dashboard($request);
    }

    public function complete(Request $request, int $session)
    {
        $model = $this->session($request, $session);
        abort_unless($model->status === 'scheduled', 422, 'Only scheduled sessions can be completed.');
        $model->update(['status' => 'completed']);

        return response()->json(['data' => $this->sessionData($model->fresh())]);
    }

    public function cancel(Request $request, int $session)
    {
        $model = $this->session($request, $session);
        abort_if(in_array($model->status, ['cancelled', 'completed'], true), 422, 'This session cannot be cancelled.');
        $model->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->sessionData($model->fresh())]);
    }

    private function coach(Request $request): CoachProfile
    {
        return CoachProfile::where('tenant_id', $request->user()->tenant_id)->where('user_id', $request->user()->id)->where('is_active', true)->firstOrFail();
    }

    private function session(Request $request, int $id): CoachingSession
    {
        return CoachingSession::where('tenant_id', $request->user()->tenant_id)->where('coach_profile_id', $this->coach($request)->id)->findOrFail($id);
    }

    private function sessionData(CoachingSession $session): array
    {
        return $session->toArray() + ['player' => User::find($session->user_id)?->only(['id', 'name'])];
    }
}

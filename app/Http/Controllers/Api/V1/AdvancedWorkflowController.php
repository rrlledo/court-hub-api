<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CoachProfile;
use App\Models\CoachStudent;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;

class AdvancedWorkflowController extends Controller
{
    public function settlements(Request $request)
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $payments = Payment::where('tenant_id', $request->user()->tenant_id)
            ->when($data['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($data['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->get();

        return response()->json(['data' => [
            'simulated' => true,
            'total_paid' => $payments->where('status', 'paid')->sum('amount'),
            'total_pending' => $payments->where('status', 'pending')->sum('amount'),
            'providers' => $payments->groupBy(fn (Payment $payment) => $payment->provider ?? 'manual')->map(fn ($items, $provider) => [
                'provider' => $provider,
                'paid_amount' => $items->where('status', 'paid')->sum('amount'),
                'pending_amount' => $items->where('status', 'pending')->sum('amount'),
                'payment_count' => $items->count(),
                'mock_events' => $items->filter(fn (Payment $payment) => (bool) data_get($payment->provider_payload, 'mock'))->count(),
            ])->values(),
        ]]);
    }

    public function coachStudents(Request $request, int $coach)
    {
        $model = $this->coach($request, $coach);
        abort_if($request->user()->hasRole('coach') && ! $request->user()->hasAnyRole(['court-owner', 'facility-manager']) && $model->user_id !== $request->user()->id, 403, 'Coaches can only view their own roster.');

        return response()->json(['data' => $this->roster($model)]);
    }

    public function addCoachStudent(Request $request, int $coach)
    {
        $model = $this->coach($request, $coach);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'revenue_share_percent' => ['nullable', 'numeric', 'between:0,100'], 'notes' => ['nullable', 'string', 'max:1000']]);
        User::where('tenant_id', $request->user()->tenant_id)->role('player')->findOrFail($data['user_id']);
        $student = CoachStudent::updateOrCreate(
            ['coach_profile_id' => $model->id, 'user_id' => $data['user_id']],
            ['tenant_id' => $request->user()->tenant_id, 'revenue_share_percent' => $data['revenue_share_percent'] ?? 0, 'notes' => $data['notes'] ?? null],
        );

        return response()->json(['data' => $this->studentData($student)], $student->wasRecentlyCreated ? 201 : 200);
    }

    public function removeCoachStudent(Request $request, int $coach, int $student)
    {
        CoachStudent::where('coach_profile_id', $this->coach($request, $coach)->id)->findOrFail($student)->delete();

        return response()->json(status: 204);
    }

    public function myCoachRoster(Request $request)
    {
        $coach = CoachProfile::where('tenant_id', $request->user()->tenant_id)->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['data' => $this->roster($coach)]);
    }

    private function coach(Request $request, int $id): CoachProfile
    {
        return CoachProfile::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function roster(CoachProfile $coach)
    {
        return CoachStudent::where('coach_profile_id', $coach->id)->latest()->get()->map(fn (CoachStudent $student) => $this->studentData($student));
    }

    private function studentData(CoachStudent $student): array
    {
        return $student->toArray() + ['player' => User::find($student->user_id)?->only(['id', 'name', 'email'])];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CoachAvailability;
use App\Models\CoachingSession;
use App\Models\CoachProfile;
use App\Models\Court;
use App\Models\InventoryItem;
use App\Models\Rental;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SportsOperationsController extends Controller
{
    public function updateCoach(Request $request, int $coach)
    {
        $model = $this->coach($request, $coach);
        $model->update($request->validate(['name' => ['sometimes', 'string', 'max:120'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:40'], 'bio' => ['nullable', 'string', 'max:2000'], 'hourly_rate' => ['sometimes', 'numeric', 'min:0'], 'is_active' => ['sometimes', 'boolean']]));

        return response()->json(['data' => $model]);
    }

    public function destroyCoach(Request $request, int $coach)
    {
        $this->coach($request, $coach)->delete();

        return response()->noContent();
    }

    public function coachAvailability(Request $request, int $coach)
    {
        return CoachAvailability::where('coach_profile_id', $this->coach($request, $coach)->id)->orderBy('day_of_week')->orderBy('starts_at')->get();
    }

    public function replaceCoachAvailability(Request $request, int $coach)
    {
        $model = $this->coach($request, $coach);
        $data = $request->validate(['availability' => ['required', 'array'], 'availability.*.day_of_week' => ['required', 'integer', 'between:0,6'], 'availability.*.starts_at' => ['required', 'date_format:H:i'], 'availability.*.ends_at' => ['required', 'date_format:H:i']]);
        foreach ($data['availability'] as $slot) {
            abort_if($slot['ends_at'] <= $slot['starts_at'], 422, 'Availability end time must be later than its start time.');
        }
        DB::transaction(function () use ($request, $model, $data) {
            CoachAvailability::where('coach_profile_id', $model->id)->delete();
            foreach ($data['availability'] as $slot) {
                CoachAvailability::create($slot + ['tenant_id' => $request->user()->tenant_id, 'coach_profile_id' => $model->id]);
            }
        });

        return $this->coachAvailability($request, $model->id);
    }

    public function coachingSessions(Request $request)
    {
        return CoachingSession::where('tenant_id', $request->user()->tenant_id)->latest('starts_at')->paginate();
    }

    public function coachRevenue(Request $request, int $coach)
    {
        $model = $this->coach($request, $coach);
        $sessions = CoachingSession::where('coach_profile_id', $model->id);

        return response()->json(['data' => ['coach_id' => $model->id, 'completed_sessions' => (clone $sessions)->where('status', 'completed')->count(), 'completed_revenue' => (clone $sessions)->where('status', 'completed')->sum('amount')]]);
    }

    public function createCoachingSession(Request $request)
    {
        $data = $request->validate(['coach_profile_id' => ['required', 'integer'], 'user_id' => ['nullable', 'integer'], 'court_id' => ['nullable', 'integer'], 'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $coach = $this->coach($request, $data['coach_profile_id']);
        $starts = Carbon::parse($data['starts_at']);
        $ends = Carbon::parse($data['ends_at']);
        abort_if(CoachingSession::where('coach_profile_id', $coach->id)->whereIn('status', ['scheduled', 'completed'])->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)->exists(), 409, 'The coach is unavailable for this time.');
        if (isset($data['court_id'])) {
            Court::where('tenant_id', $request->user()->tenant_id)->findOrFail($data['court_id']);
        }
        $userId = $data['user_id'] ?? $request->user()->id;
        $this->user($request, $userId);
        $session = CoachingSession::create($data + ['tenant_id' => $request->user()->tenant_id, 'user_id' => $userId, 'amount' => $coach->hourly_rate * ($starts->diffInMinutes($ends) / 60)]);

        return response()->json(['data' => $session], 201);
    }

    public function completeCoachingSession(Request $request, int $session)
    {
        $model = $this->session($request, $session);
        abort_unless($model->status === 'scheduled', 422, 'Only scheduled sessions can be completed.');
        $model->update(['status' => 'completed']);

        return response()->json(['data' => $model]);
    }

    public function cancelCoachingSession(Request $request, int $session)
    {
        $model = $this->session($request, $session);
        abort_if(in_array($model->status, ['cancelled', 'completed'], true), 422, 'This session cannot be cancelled.');
        $model->update(['status' => 'cancelled']);

        return response()->json(['data' => $model]);
    }

    public function updateInventory(Request $request, int $inventoryItem)
    {
        $item = InventoryItem::where('tenant_id', $request->user()->tenant_id)->findOrFail($inventoryItem);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'rental_price' => ['sometimes', 'numeric', 'min:0'], 'deposit_amount' => ['sometimes', 'numeric', 'min:0'], 'is_active' => ['sometimes', 'boolean'], 'quantity_total' => ['sometimes', 'integer', 'min:0']]);
        if (isset($data['quantity_total'])) {
            $inUse = $item->quantity_total - $item->quantity_available;
            abort_if($data['quantity_total'] < $inUse, 422, 'Quantity cannot be lower than equipment currently rented out.');
            $data['quantity_available'] = $data['quantity_total'] - $inUse;
        }
        $item->update($data);

        return response()->json(['data' => $item]);
    }

    public function showRental(Request $request, int $rental)
    {
        return response()->json(['data' => $this->rental($request, $rental)]);
    }

    public function extendRental(Request $request, int $rental)
    {
        $model = $this->rental($request, $rental);
        $data = $request->validate(['due_at' => ['required', 'date', 'after:now']]);
        abort_unless($model->status === 'active', 422, 'Only active rentals can be extended.');
        $model->update($data);

        return response()->json(['data' => $model]);
    }

    public function closeRental(Request $request, int $rental)
    {
        $model = $this->rental($request, $rental);
        $data = $request->validate(['damage_fee' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:1000']]);
        abort_unless($model->status === 'active', 422, 'This rental is already closed.');
        DB::transaction(function () use ($model, $data) {
            InventoryItem::whereKey($model->inventory_item_id)->increment('quantity_available', $model->quantity);
            $model->update($data + ['status' => 'returned', 'returned_at' => now(), 'deposit_returned_at' => now()]);
        });

        return response()->json(['data' => $model->fresh()]);
    }

    public function overdueRentals(Request $request)
    {
        return Rental::where('tenant_id', $request->user()->tenant_id)->where('status', 'active')->where('due_at', '<', now())->paginate();
    }

    public function showTournament(Request $request, int $tournament)
    {
        return response()->json(['data' => $this->tournament($request, $tournament)]);
    }

    public function updateTournament(Request $request, int $tournament)
    {
        $model = $this->tournament($request, $tournament);
        $model->update($request->validate(['name' => ['sometimes', 'string', 'max:150'], 'status' => ['sometimes', 'in:draft,open,ongoing,completed,cancelled'], 'capacity' => ['nullable', 'integer', 'min:1'], 'ends_at' => ['nullable', 'date']]));

        return response()->json(['data' => $model]);
    }

    public function registrations(Request $request, int $tournament)
    {
        return TournamentRegistration::where('tournament_id', $this->tournament($request, $tournament)->id)
            ->with('user:id,name,email')
            ->paginate();
    }

    public function cancelRegistration(Request $request, int $tournament, int $registration)
    {
        $model = TournamentRegistration::where('tournament_id', $this->tournament($request, $tournament)->id)->findOrFail($registration);
        $model->update(['status' => 'cancelled']);

        return response()->json(['data' => $model]);
    }

    public function matches(Request $request, int $tournament)
    {
        return TournamentMatch::where('tournament_id', $this->tournament($request, $tournament)->id)->orderBy('round_number')->orderBy('match_number')->get();
    }

    public function createMatch(Request $request, int $tournament)
    {
        $model = $this->tournament($request, $tournament);
        $data = $request->validate(['court_id' => ['nullable', 'integer'], 'player_one_registration_id' => ['nullable', 'integer'], 'player_two_registration_id' => ['nullable', 'integer'], 'round_number' => ['required', 'integer', 'min:1'], 'match_number' => ['required', 'integer', 'min:1'], 'starts_at' => ['nullable', 'date']]);
        if (isset($data['court_id'])) {
            Court::where('tenant_id', $request->user()->tenant_id)->findOrFail($data['court_id']);
        } foreach (['player_one_registration_id', 'player_two_registration_id'] as $key) {
            if (isset($data[$key])) {
                TournamentRegistration::where('tournament_id', $model->id)->findOrFail($data[$key]);
            }
        }

        return response()->json(['data' => TournamentMatch::create($data + ['tenant_id' => $request->user()->tenant_id, 'tournament_id' => $model->id])], 201);
    }

    public function updateMatch(Request $request, int $match)
    {
        $model = TournamentMatch::where('tenant_id', $request->user()->tenant_id)->findOrFail($match);
        $data = $request->validate(['winner_registration_id' => ['nullable', 'integer'], 'score' => ['nullable', 'string', 'max:100'], 'status' => ['sometimes', 'in:scheduled,ongoing,completed,cancelled'], 'starts_at' => ['nullable', 'date']]);
        if (isset($data['winner_registration_id'])) {
            TournamentRegistration::where('tournament_id', $model->tournament_id)->findOrFail($data['winner_registration_id']);
        } $model->update($data);

        return response()->json(['data' => $model]);
    }

    private function coach(Request $r, int $id): CoachProfile
    {
        return CoachProfile::where('tenant_id', $r->user()->tenant_id)->findOrFail($id);
    }

    private function session(Request $r, int $id): CoachingSession
    {
        return CoachingSession::where('tenant_id', $r->user()->tenant_id)->findOrFail($id);
    }

    private function rental(Request $r, int $id): Rental
    {
        $rental = Rental::where('tenant_id', $r->user()->tenant_id)->findOrFail($id);
        Gate::forUser($r->user())->authorize('view', $rental);

        return $rental;
    }

    private function tournament(Request $r, int $id): Tournament
    {
        return Tournament::where('tenant_id', $r->user()->tenant_id)->findOrFail($id);
    }

    private function user(Request $r, int $id): User
    {
        return User::where('tenant_id', $r->user()->tenant_id)->findOrFail($id);
    }
}

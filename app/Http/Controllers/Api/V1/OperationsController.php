<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\CheckIn;
use App\Models\CoachProfile;
use App\Models\InventoryItem;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsController extends Controller
{
    public function membershipPlans(Request $request)
    {
        return MembershipPlan::where('tenant_id', $request->user()->tenant_id)->paginate();
    }

    public function createMembershipPlan(Request $request)
    {
        $plan = MembershipPlan::create($request->validate(['name' => ['required', 'string', 'max:120'], 'plan_type' => ['nullable', 'in:standard,family,corporate,session_package'], 'billing_period' => ['required', 'in:monthly,annual,custom'], 'price' => ['required', 'numeric', 'min:0'], 'duration_days' => ['required', 'integer', 'min:1'], 'discount_percent' => ['nullable', 'numeric', 'between:0,100'], 'priority_booking' => ['nullable', 'boolean'], 'session_count' => ['nullable', 'integer', 'min:1']]) + ['tenant_id' => $request->user()->tenant_id]);

        return response()->json(['data' => $plan], 201);
    }

    public function memberships(Request $request)
    {
        return Membership::where('tenant_id', $request->user()->tenant_id)->paginate();
    }

    public function activateMembership(Request $request)
    {
        $data = $request->validate(['membership_plan_id' => ['required', 'integer'], 'user_id' => ['nullable', 'integer'], 'starts_on' => ['nullable', 'date'], 'auto_renew' => ['nullable', 'boolean']]);
        $plan = MembershipPlan::where('tenant_id', $request->user()->tenant_id)->findOrFail($data['membership_plan_id']);
        $userId = $data['user_id'] ?? $request->user()->id;
        $this->user($request, $userId);
        $starts = Carbon::parse($data['starts_on'] ?? now())->startOfDay();
        $membership = Membership::create(['tenant_id' => $request->user()->tenant_id, 'membership_plan_id' => $plan->id, 'user_id' => $userId, 'starts_on' => $starts, 'ends_on' => $starts->copy()->addDays($plan->duration_days - 1), 'status' => 'active', 'auto_renew' => $data['auto_renew'] ?? false, 'remaining_sessions' => $plan->session_count]);

        return response()->json(['data' => $membership], 201);
    }

    public function coaches(Request $request)
    {
        return CoachProfile::where('tenant_id', $request->user()->tenant_id)->paginate();
    }

    public function createCoach(Request $request)
    {
        $data = $request->validate(['user_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:40'], 'bio' => ['nullable', 'string', 'max:2000'], 'hourly_rate' => ['required', 'numeric', 'min:0']]);
        if (isset($data['user_id'])) {
            $user = $this->user($request, $data['user_id']);
            abort_unless($user->hasRole('coach'), 422, 'The selected account must have the coach role.');
            abort_if(CoachProfile::where('tenant_id', $request->user()->tenant_id)->where('user_id', $user->id)->exists(), 422, 'This coach already has a profile.');
        }
        $coach = CoachProfile::create($data + ['tenant_id' => $request->user()->tenant_id]);

        return response()->json(['data' => $coach], 201);
    }

    public function inventory(Request $request)
    {
        return InventoryItem::where('tenant_id', $request->user()->tenant_id)->paginate();
    }

    public function createInventory(Request $request)
    {
        $data = $request->validate(['branch_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120'], 'sku' => ['nullable', 'string', 'max:80'], 'quantity_total' => ['required', 'integer', 'min:0'], 'rental_price' => ['required', 'numeric', 'min:0'], 'deposit_amount' => ['nullable', 'numeric', 'min:0']]);
        $this->branch($request, $data['branch_id']);
        $item = InventoryItem::create($data + ['tenant_id' => $request->user()->tenant_id, 'quantity_available' => $data['quantity_total']]);

        return response()->json(['data' => $item], 201);
    }

    public function rentals(Request $request)
    {
        return Rental::visibleTo($request->user())
            ->with(['user:id,name,email', 'inventoryItem:id,name'])
            ->latest('rented_at')
            ->paginate();
    }

    public function rentalUsers(Request $request)
    {
        return response()->json(['data' => User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->role('player')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])]);
    }

    public function createRental(Request $request)
    {
        $data = $request->validate(['inventory_item_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1'], 'user_id' => ['nullable', 'integer'], 'due_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $rental = DB::transaction(function () use ($request, $data) {
            $item = InventoryItem::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($data['inventory_item_id']);
            abort_if($item->quantity_available < $data['quantity'], 409, 'Insufficient equipment inventory.');
            $userId = $data['user_id'] ?? $request->user()->id;
            $this->user($request, $userId);
            $item->decrement('quantity_available', $data['quantity']);

            return Rental::create($data + ['tenant_id' => $request->user()->tenant_id, 'user_id' => $userId, 'rented_at' => now(), 'amount' => $item->rental_price * $data['quantity'], 'deposit_amount' => $item->deposit_amount * $data['quantity']]);
        });

        return response()->json(['data' => $rental], 201);
    }

    public function returnRental(Request $request, int $rental)
    {
        $model = Rental::where('tenant_id', $request->user()->tenant_id)->findOrFail($rental);
        abort_if($model->status !== 'active', 422, 'This rental is already closed.');
        DB::transaction(function () use ($model) {
            InventoryItem::whereKey($model->inventory_item_id)->increment('quantity_available', $model->quantity);
            $model->update(['status' => 'returned', 'returned_at' => now()]);
        });

        return response()->json(['data' => $model->fresh()]);
    }

    public function tournaments(Request $request)
    {
        return Tournament::where('tenant_id', $request->user()->tenant_id)->paginate();
    }

    public function createTournament(Request $request)
    {
        $data = $request->validate(['branch_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:150'], 'format' => ['required', 'in:singles,doubles,team'], 'starts_at' => ['required', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'], 'entry_fee' => ['nullable', 'numeric', 'min:0'], 'capacity' => ['nullable', 'integer', 'min:1']]);
        $this->branch($request, $data['branch_id']);
        $tournament = Tournament::create($data + ['tenant_id' => $request->user()->tenant_id]);

        return response()->json(['data' => $tournament], 201);
    }

    public function registerTournament(Request $request, int $tournament)
    {
        $model = Tournament::where('tenant_id', $request->user()->tenant_id)->findOrFail($tournament);
        abort_if(! in_array($model->status, ['draft', 'open'], true), 422, 'Tournament registration is closed.');
        $count = TournamentRegistration::where('tournament_id', $model->id)->count();
        abort_if($model->capacity && $count >= $model->capacity, 409, 'Tournament capacity has been reached.');
        $registration = TournamentRegistration::firstOrCreate(['tournament_id' => $model->id, 'user_id' => $request->user()->id], ['tenant_id' => $request->user()->tenant_id]);

        return response()->json(['data' => $registration], $registration->wasRecentlyCreated ? 201 : 200);
    }

    public function payments(Request $request)
    {
        return Payment::where('tenant_id', $request->user()->tenant_id)->paginate();
    }

    public function recordPayment(Request $request)
    {
        $data = $request->validate(['booking_id' => ['nullable', 'integer'], 'amount' => ['required', 'numeric', 'min:0.01'], 'method' => ['required', 'in:cash,gcash,maya,card'], 'provider_payload' => ['nullable', 'array']]);
        $booking = isset($data['booking_id']) ? Booking::where('tenant_id', $request->user()->tenant_id)->findOrFail($data['booking_id']) : null;
        $payment = Payment::create($data + ['tenant_id' => $request->user()->tenant_id, 'user_id' => $booking?->user_id ?? $request->user()->id, 'reference' => 'PAY-'.Str::upper(Str::random(10)), 'status' => 'paid']);
        if ($booking && $booking->status === 'reserved') {
            $booking->update(['status' => 'confirmed', 'expires_at' => null]);
        }

        return response()->json(['data' => $payment], 201);
    }

    public function checkIn(Request $request)
    {
        $data = $request->validate(['booking_id' => ['required', 'integer'], 'method' => ['nullable', 'in:front-desk,qr']]);
        $booking = Booking::where('tenant_id', $request->user()->tenant_id)->where('status', 'confirmed')->findOrFail($data['booking_id']);

        return $this->recordCheckIn($request, $booking, $data['method'] ?? 'front-desk');
    }

    public function qrCheckIn(Request $request)
    {
        $data = $request->validate(['qr_code' => ['required', 'string', 'max:255']]);
        $booking = Booking::where('tenant_id', $request->user()->tenant_id)->where('status', 'confirmed')->where('qr_code', $data['qr_code'])->firstOrFail();

        return $this->recordCheckIn($request, $booking, 'qr');
    }

    private function recordCheckIn(Request $request, Booking $booking, string $method)
    {
        $checkIn = CheckIn::firstOrCreate(['booking_id' => $booking->id], ['tenant_id' => $request->user()->tenant_id, 'user_id' => $booking->user_id, 'checked_in_by' => $request->user()->id, 'method' => $method, 'checked_in_at' => now()]);

        return response()->json(['data' => $checkIn], $checkIn->wasRecentlyCreated ? 201 : 200);
    }

    public function dashboard(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $data = Cache::remember("tenant:{$tenantId}:dashboard", now()->addMinutes(5), function () use ($tenantId) {
            return ['bookings' => Booking::where('tenant_id', $tenantId)->count(), 'confirmed_bookings' => Booking::where('tenant_id', $tenantId)->where('status', 'confirmed')->count(), 'booking_revenue' => Payment::where('tenant_id', $tenantId)->where('status', 'paid')->sum('amount'), 'active_memberships' => Membership::where('tenant_id', $tenantId)->where('status', 'active')->count(), 'active_rentals' => Rental::where('tenant_id', $tenantId)->where('status', 'active')->count()];
        });

        return response()->json(['data' => $data]);
    }

    private function branch(Request $request, int $id): Branch
    {
        return Branch::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function user(Request $request, int $id): User
    {
        return User::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }
}

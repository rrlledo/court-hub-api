<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BranchHoliday;
use App\Models\Court;
use App\Models\CourtMaintenance;
use App\Models\OperatingHour;
use App\Models\PricingRule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $query = Booking::where('tenant_id', $request->user()->tenant_id)->latest('starts_at');
        if ($request->user()->hasRole('player') && $request->user()->getRoleNames()->count() === 1) $query->where('user_id', $request->user()->id);
        if ($request->filled('court_id')) {
            $query->where('court_id', $request->integer('court_id'));
        }
        if ($request->filled('date')) {
            $query->whereDate('starts_at', $request->date('date'));
        }

        return BookingResource::collection($query->paginate());
    }

    public function availability(Request $request, int $court)
    {
        $date = $request->validate(['date' => ['required', 'date']])['date'];
        $model = Court::where('tenant_id', $request->user()->tenant_id)->findOrFail($court);
        $day = Carbon::parse($date);
        $bookings = $this->activeBookings($model->id, $day->copy()->startOfDay(), $day->copy()->endOfDay())->get();
        $maintenance = CourtMaintenance::query()->where('court_id', $model->id)->whereIn('status', ['scheduled', 'in_progress'])->where('starts_at', '<', $day->copy()->endOfDay())->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $day->copy()->startOfDay()))->get();
        $holiday = BranchHoliday::query()->where('branch_id', $model->branch_id)->whereDate('holiday_date', $day)->where('is_closed', true)->exists();
        $hours = OperatingHour::query()->where('branch_id', $model->branch_id)->where('day_of_week', $day->dayOfWeek)->first();

        return response()->json(['data' => ['court' => $model->only('id', 'name', 'status'), 'date' => $date, 'is_closed' => $holiday || $hours?->is_closed, 'operating_hours' => $hours?->only('opens_at', 'closes_at', 'is_closed'), 'bookings' => BookingResource::collection($bookings)->resolve(), 'maintenance' => $maintenance]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['court_id' => ['required', 'integer'], 'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $booking = DB::transaction(function () use ($request, $data) {
            $court = Court::where('tenant_id', $request->user()->tenant_id)->where('status', 'active')->lockForUpdate()->findOrFail($data['court_id']);
            $startsAt = Carbon::parse($data['starts_at']);
            $endsAt = Carbon::parse($data['ends_at']);
            $this->ensureCourtCanBeBooked($court, $startsAt, $endsAt);
            Booking::where('court_id', $court->id)->where('status', 'reserved')->where('expires_at', '<=', now())->update(['status' => 'expired']);
            if ($this->activeBookings($court->id, $startsAt, $endsAt)->exists()) {
                abort(409, 'The selected court is no longer available for this time.');
            }
            $hours = $startsAt->diffInMinutes($endsAt) / 60;

            return Booking::create(['tenant_id' => $request->user()->tenant_id, 'court_id' => $court->id, 'user_id' => $request->user()->id, 'reference' => 'CH-'.Str::upper(Str::random(10)), 'source' => $request->input('source', 'online'), 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'status' => 'reserved', 'amount' => $this->priceFor($court, $startsAt) * $hours, 'currency' => 'PHP', 'expires_at' => now()->addMinutes(10), 'notes' => $data['notes'] ?? null]);
        });

        return (new BookingResource($booking))->response()->setStatusCode(201);
    }

    public function walkIn(Request $request)
    {
        $request->merge(['source' => 'walk_in']);

        return $this->store($request);
    }

    public function qrBooking(Request $request)
    {
        $request->merge(['source' => 'qr']);

        return $this->store($request);
    }

    public function show(Request $request, int $booking)
    {
        return new BookingResource($this->booking($request, $booking));
    }

    public function confirm(Request $request, int $booking)
    {
        $model = $this->booking($request, $booking);
        abort_if($model->status !== 'reserved' || $model->expires_at?->isPast(), 422, 'Only active reservations can be confirmed.');
        $model->update(['status' => 'confirmed', 'expires_at' => null]);

        return new BookingResource($model);
    }

    public function cancel(Request $request, int $booking)
    {
        $model = $this->booking($request, $booking);
        abort_unless($model->user_id === $request->user()->id || $request->user()->hasAnyRole(['court-owner', 'facility-manager', 'front-desk']), 403, 'You cannot cancel this booking.');
        abort_unless(in_array($model->status, ['reserved', 'confirmed'], true), 422, 'This booking cannot be cancelled.');
        abort_if($model->starts_at->isPast() || ($model->status === 'reserved' && $model->expires_at?->isPast()), 422, 'Only upcoming active bookings can be cancelled.');
        $model->update(['status' => 'cancelled']);

        return new BookingResource($model);
    }

    private function booking(Request $request, int $id): Booking
    {
        return Booking::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function activeBookings(int $courtId, Carbon $startsAt, Carbon $endsAt)
    {
        return Booking::where('court_id', $courtId)->whereIn('status', ['reserved', 'confirmed'])->where(function ($query) {
            $query->where('status', 'confirmed')->orWhere('expires_at', '>', now());
        })->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt);
    }

    private function ensureCourtCanBeBooked(Court $court, Carbon $startsAt, Carbon $endsAt): void
    {
        abort_if(BranchHoliday::query()->where('branch_id', $court->branch_id)->whereDate('holiday_date', $startsAt)->where('is_closed', true)->exists(), 422, 'The branch is closed for this holiday.');
        $hours = OperatingHour::query()->where('branch_id', $court->branch_id)->where('day_of_week', $startsAt->dayOfWeek)->first();
        if ($hours) {
            abort_if($hours->is_closed || $startsAt->format('H:i:s') < $hours->opens_at || $endsAt->format('H:i:s') > $hours->closes_at, 422, 'The requested time is outside branch operating hours.');
        }
        abort_if(CourtMaintenance::query()->where('court_id', $court->id)->whereIn('status', ['scheduled', 'in_progress'])->where('starts_at', '<', $endsAt)->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $startsAt))->exists(), 409, 'The court is unavailable due to maintenance.');
    }

    private function priceFor(Court $court, Carbon $startsAt): float
    {
        $time = $startsAt->format('H:i:s');
        $rule = PricingRule::query()->where('court_id', $court->id)->where('is_active', true)->where(fn ($query) => $query->whereNull('day_of_week')->orWhere('day_of_week', $startsAt->dayOfWeek))->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $time))->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $time))->orderByDesc('day_of_week')->orderByDesc('starts_at')->first();

        return (float) ($rule?->price ?? $court->base_price);
    }
}

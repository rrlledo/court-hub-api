<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Models\RecurringReservation;
use App\Models\Refund;
use App\Models\Waitlist;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Services\BookingRules;
use App\Http\Resources\BookingResource;

class BookingLifecycleController extends Controller
{
    private function booking(Request $r, int $id): Booking
    {
        return Booking::where('tenant_id', $r->user()->tenant_id)->findOrFail($id);
    }

    public function history(Request $r)
    {
        return Booking::where('tenant_id', $r->user()->tenant_id)->where('user_id', $r->user()->id)->latest('starts_at')->paginate();
    }

    public function waitlist(Request $r)
    {
        $d = $r->validate(['court_id' => ['required', 'integer'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at']]);
        Court::where('tenant_id', $r->user()->tenant_id)->findOrFail($d['court_id']);

        return response()->json(['data' => Waitlist::create($d + ['tenant_id' => $r->user()->tenant_id, 'user_id' => $r->user()->id])], 201);
    }

    public function reschedule(Request $r, int $booking)
    {
        return DB::transaction(function () use ($r, $booking) {
        $b = $this->booking($r, $booking);
        // Match creation's court lock before reloading the booking for mutation.
        $court = Court::where('tenant_id', $r->user()->tenant_id)->lockForUpdate()->findOrFail($b->court_id);
        $b = Booking::whereKey($b->id)->lockForUpdate()->firstOrFail();
        abort_unless($b->user_id === $r->user()->id || $r->user()->hasAnyRole(['court-owner', 'facility-manager', 'front-desk']), 403, 'You cannot reschedule this booking.');
        abort_unless(in_array($b->status, ['reserved', 'confirmed'], true) && $b->starts_at->isFuture(), 422, 'Only upcoming active bookings can be rescheduled.');
        abort_if($b->status === 'reserved' && $b->expires_at?->isPast(), 422, 'This reservation has expired.');
        $d = $r->validate(['starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at']]);
        abort_if(in_array($b->status, ['cancelled', 'expired'], true), 422, 'This booking cannot be rescheduled.');
        $startsAt = Carbon::parse($d['starts_at'])->utc();
        $endsAt = Carbon::parse($d['ends_at'])->utc();
        $rules = app(BookingRules::class);
        $rules->validate($court, $startsAt, $endsAt);
        abort_if(abs($rules->amount($court, $startsAt, $endsAt) - (float) $b->amount) > 0.009, 422, 'This time changes the booking price. Please contact the facility to arrange the price difference.');
        $hasConflict = Booking::query()
            ->where('court_id', $b->court_id)
            ->whereKeyNot($b->id)
            ->whereIn('status', ['reserved', 'confirmed'])
            ->where(function ($query) {
                $query->where('status', 'confirmed')->orWhere('expires_at', '>', now());
            })
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
        abort_if($hasConflict, 409, 'The selected court is no longer available for this time.');
        $b->update(['starts_at' => $startsAt, 'ends_at' => $endsAt]);

        return new BookingResource($b);
        });
    }

    public function refund(Request $r, int $booking)
    {
        $b = $this->booking($r, $booking);
        $d = $r->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:'.$b->amount], 'reason' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => Refund::create($d + ['tenant_id' => $r->user()->tenant_id, 'booking_id' => $b->id, 'user_id' => $r->user()->id])], 201);
    }

    public function recurring(Request $r)
    {
        $d = $r->validate(['court_id' => ['required', 'integer'], 'day_of_week' => ['required', 'integer', 'between:0,6'], 'starts_at' => ['required', 'date_format:H:i'], 'duration_minutes' => ['required', 'integer', 'min:30'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on']]);
        Court::where('tenant_id', $r->user()->tenant_id)->findOrFail($d['court_id']);

        return response()->json(['data' => RecurringReservation::create($d + ['tenant_id' => $r->user()->tenant_id, 'user_id' => $r->user()->id])], 201);
    }

    public function qr(Request $r, int $booking)
    {
        $b = $this->booking($r, $booking);
        $b->update(['qr_code' => Str::upper(Str::random(16))]);

        return response()->json(['data' => ['booking_id' => $b->id, 'qr_code' => $b->qr_code]]);
    }

    public function checkQr(Request $r)
    {
        $d = $r->validate(['qr_code' => ['required', 'string']]);
        $b = Booking::where('tenant_id', $r->user()->tenant_id)->where('qr_code', $d['qr_code'])->firstOrFail();

        return response()->json(['data' => $b]);
    }
}

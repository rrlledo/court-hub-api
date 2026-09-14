<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CoachingSession;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Refund;
use App\Models\Rental;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function revenue(Request $request)
    {
        [$from, $to] = $this->range($request);
        $payments = Payment::where('tenant_id', $request->user()->tenant_id)->where('status', 'paid')->whereBetween('created_at', [$from, $to])->get();

        return response()->json(['data' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'total' => $payments->sum('amount'), 'by_day' => $payments->groupBy(fn ($payment) => $payment->paid_at?->toDateString() ?? $payment->created_at->toDateString())->map(fn ($items, $date) => ['date' => $date, 'amount' => $items->sum('amount'), 'count' => $items->count()])->values()]]);
    }

    public function occupancy(Request $request)
    {
        [$from, $to] = $this->range($request);
        $bookings = $this->bookings($request, $from, $to);
        $minutes = $bookings->sum(fn ($booking) => $booking->starts_at->diffInMinutes($booking->ends_at));
        $windowMinutes = max(1, $from->diffInMinutes($to));

        return response()->json(['data' => ['booked_minutes' => $minutes, 'period_minutes' => $windowMinutes, 'occupancy_percent' => round($minutes / $windowMinutes * 100, 2), 'booking_count' => $bookings->count()]]);
    }

    public function peakHours(Request $request)
    {
        [$from, $to] = $this->range($request);
        $hours = $this->bookings($request, $from, $to)->groupBy(fn ($booking) => $booking->starts_at->format('H:00'))->map(fn ($items, $hour) => ['hour' => $hour, 'booking_count' => $items->count(), 'booked_minutes' => $items->sum(fn ($booking) => $booking->starts_at->diffInMinutes($booking->ends_at))])->sortByDesc('booking_count')->values();

        return response()->json(['data' => $hours]);
    }

    public function courtUtilization(Request $request)
    {
        [$from, $to] = $this->range($request);
        $courts = $this->bookings($request, $from, $to)->groupBy('court_id')->map(fn ($items, $courtId) => ['court_id' => $courtId, 'booking_count' => $items->count(), 'booked_minutes' => $items->sum(fn ($booking) => $booking->starts_at->diffInMinutes($booking->ends_at))])->values();

        return response()->json(['data' => $courts]);
    }

    public function membershipSales(Request $request)
    {
        [$from, $to] = $this->range($request);
        $memberships = Membership::where('tenant_id', $request->user()->tenant_id)->whereBetween('created_at', [$from, $to])->get();

        return response()->json(['data' => ['sales_count' => $memberships->count(), 'by_plan' => $memberships->groupBy('membership_plan_id')->map(fn ($items, $planId) => ['membership_plan_id' => $planId, 'count' => $items->count()])->values()]]);
    }

    public function coachRevenue(Request $request)
    {
        [$from, $to] = $this->range($request);
        $sessions = CoachingSession::where('tenant_id', $request->user()->tenant_id)->where('status', 'completed')->whereBetween('starts_at', [$from, $to])->get();

        return response()->json(['data' => ['total' => $sessions->sum('amount'), 'by_coach' => $sessions->groupBy('coach_profile_id')->map(fn ($items, $coachId) => ['coach_id' => $coachId, 'sessions' => $items->count(), 'amount' => $items->sum('amount')])->values()]]);
    }

    public function tournamentRevenue(Request $request)
    {
        [$from, $to] = $this->range($request);
        $tournaments = Tournament::where('tenant_id', $request->user()->tenant_id)->whereBetween('starts_at', [$from, $to])->get();
        $registrations = TournamentRegistration::whereIn('tournament_id', $tournaments->pluck('id'))->where('status', 'registered')->get()->groupBy('tournament_id');
        $items = $tournaments->map(fn ($tournament) => ['tournament_id' => $tournament->id, 'registrations' => $registrations->get($tournament->id, collect())->count(), 'estimated_revenue' => $registrations->get($tournament->id, collect())->count() * $tournament->entry_fee]);

        return response()->json(['data' => ['total_estimated_revenue' => $items->sum('estimated_revenue'), 'tournaments' => $items->values()]]);
    }

    public function rentalRevenue(Request $request)
    {
        [$from, $to] = $this->range($request);
        $rentals = Rental::where('tenant_id', $request->user()->tenant_id)->whereBetween('rented_at', [$from, $to])->get();

        return response()->json(['data' => ['rental_count' => $rentals->count(), 'rental_revenue' => $rentals->sum('amount'), 'damage_fees' => $rentals->sum('damage_fee'), 'total' => $rentals->sum('amount') + $rentals->sum('damage_fee')]]);
    }

    public function refunds(Request $request)
    {
        [$from, $to] = $this->range($request);
        $tenantId = $request->user()->tenant_id;
        $bookingRefunds = Refund::where('tenant_id', $tenantId)->whereBetween('created_at', [$from, $to])->get();
        $paymentRefunds = PaymentRefund::where('tenant_id', $tenantId)->whereBetween('created_at', [$from, $to])->get();

        return response()->json(['data' => ['booking_refunds' => ['count' => $bookingRefunds->count(), 'amount' => $bookingRefunds->sum('amount')], 'payment_refunds' => ['count' => $paymentRefunds->count(), 'amount' => $paymentRefunds->sum('amount')], 'total_amount' => $bookingRefunds->sum('amount') + $paymentRefunds->sum('amount')]]);
    }

    private function range(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return [Carbon::parse($data['from'] ?? now()->startOfMonth())->startOfDay(), Carbon::parse($data['to'] ?? now())->endOfDay()];
    }

    private function bookings(Request $request, Carbon $from, Carbon $to)
    {
        return Booking::where('tenant_id', $request->user()->tenant_id)->where('status', 'confirmed')->whereBetween('starts_at', [$from, $to])->get();
    }
}

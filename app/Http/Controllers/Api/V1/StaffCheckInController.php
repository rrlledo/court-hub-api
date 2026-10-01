<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CheckIn;
use App\Models\Membership;
use App\Models\MembershipCard;
use App\Models\MembershipCheckIn;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffCheckInController extends Controller
{
    public function scan(Request $request)
    {
        $data = $request->validate(['qr_code' => ['required', 'string', 'max:255']]);
        $code = strtoupper(trim($data['qr_code']));

        return DB::transaction(function () use ($request, $code) {
            $booking = Booking::where('tenant_id', $request->user()->tenant_id)->where('qr_code', $code)->lockForUpdate()->first();
            if ($booking) {
                abort_unless($booking->status === 'confirmed', 422, 'This booking is not eligible for check-in.');
                $checkIn = CheckIn::firstOrCreate(['booking_id' => $booking->id], [
                    'tenant_id' => $booking->tenant_id,
                    'user_id' => $booking->user_id,
                    'checked_in_by' => $request->user()->id,
                    'method' => 'qr',
                    'checked_in_at' => now(),
                ]);

                return response()->json(['data' => [
                    'type' => 'booking',
                    'already_checked_in' => ! $checkIn->wasRecentlyCreated,
                    'check_in' => $checkIn,
                    'booking' => $booking->only(['id', 'reference', 'starts_at', 'ends_at', 'status']),
                    'member' => User::find($booking->user_id)?->only(['id', 'name']),
                ]], $checkIn->wasRecentlyCreated ? 201 : 200);
            }

            $card = MembershipCard::where('tenant_id', $request->user()->tenant_id)->where('qr_code', $code)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $membership = Membership::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($card->membership_id);
            abort_unless($membership->status === 'active' && $membership->starts_on->startOfDay()->lte(today()) && $membership->ends_on->endOfDay()->gte(today()), 422, 'This membership is not currently active.');
            $checkIn = MembershipCheckIn::create([
                'tenant_id' => $membership->tenant_id,
                'membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'checked_in_by' => $request->user()->id,
                'method' => 'qr',
                'checked_in_at' => now(),
            ]);

            return response()->json(['data' => [
                'type' => 'membership',
                'check_in' => $checkIn,
                'membership' => $membership->only(['id', 'starts_on', 'ends_on', 'status', 'remaining_sessions']) + [
                    'plan' => MembershipPlan::find($membership->membership_plan_id)?->only(['id', 'name', 'plan_type']),
                    'card_number' => $card->card_number,
                ],
                'member' => User::find($membership->user_id)?->only(['id', 'name']),
            ]], 201);
        });
    }
}

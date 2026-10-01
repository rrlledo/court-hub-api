<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\Membership;
use App\Models\MembershipCard;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettleCheckout
{
    public function apply(array $session): Payment
    {
        $attributes = $session['attributes'];
        $payment = Payment::where('provider', 'paymongo')->where('reference', $attributes['reference_number'] ?? '')->firstOrFail();
        abort_unless(($attributes['livemode'] ?? null) === str_starts_with((string) config('services.paymongo.secret_key'), 'sk_live_'), 422, 'Payment mode mismatch.');

        return DB::transaction(function () use ($payment, $session, $attributes) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_if($payment->provider_reference && $payment->provider_reference !== $session['id'], 422, 'Checkout reference mismatch.');
            $payment->provider_reference = $session['id'];
            if (in_array($payment->status, ['paid', 'paid_review'], true)) {
                return $payment;
            }
            $paid = collect($attributes['payments'] ?? [])->filter(fn ($item) => data_get($item, 'attributes.status') === 'paid');
            if ($paid->isNotEmpty()) {
                $validAmount = $paid->sum('attributes.amount') === (int) round((float) $payment->amount * 100)
                    && $paid->every(fn ($item) => data_get($item, 'attributes.currency') === $payment->currency);
                [$confirmed, $notification] = $this->settleSubject($payment, $validAmount);
                $payment->status = $confirmed ? 'paid' : 'paid_review';
                $payment->paid_at = now();
                UserNotification::create([
                    'tenant_id' => $payment->tenant_id,
                    'user_id' => $payment->user_id,
                    'channel' => 'in_app',
                    'type' => 'payment',
                    'title' => $confirmed ? $notification['title'] : 'Payment needs review',
                    'message' => $confirmed ? $notification['message'] : 'Payment was received but could not be completed. Contact your facility for resolution or a refund.',
                    'data' => ['payment_id' => $payment->id, ...$notification['data']],
                ]);
            } elseif (($attributes['status'] ?? '') === 'expired') {
                $payment->status = 'expired';
            }
            $payment->provider_payload = array_merge($payment->provider_payload ?? [], [
                'attempt_failed' => ! empty(data_get($attributes, 'payment_intent.attributes.last_payment_error')),
            ]);
            $payment->save();

            return $payment;
        });
    }

    private function settleSubject(Payment $payment, bool $validAmount): array
    {
        if ($payment->booking_id) {
            $booking = Booking::findOrFail($payment->booking_id);
            Court::whereKey($booking->court_id)->lockForUpdate()->firstOrFail();
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $confirmed = $validAmount && $booking->status === 'reserved' && $booking->expires_at?->isFuture()
                && $booking->starts_at->isFuture() && (float) $booking->amount === (float) $payment->amount;
            if ($confirmed) {
                $booking->update(['status' => 'confirmed', 'expires_at' => null]);
            }

            return [$confirmed, ['title' => 'Booking confirmed', 'message' => 'Payment received for '.$booking->reference, 'data' => ['booking_id' => $booking->id]]];
        }

        $membership = Membership::whereKey($payment->membership_id)->lockForUpdate()->firstOrFail();
        $plan = MembershipPlan::whereKey($membership->membership_plan_id)->lockForUpdate()->firstOrFail();
        $confirmed = $validAmount && $membership->status === 'pending' && $plan->is_active
            && (float) $plan->price === (float) $payment->amount && $plan->currency === $payment->currency;
        if ($confirmed) {
            $membership->update(['status' => 'active']);
            MembershipCard::firstOrCreate(['membership_id' => $membership->id], [
                'tenant_id' => $membership->tenant_id,
                'card_number' => 'MC-'.Str::upper(Str::random(12)),
                'qr_code' => Str::upper(Str::random(24)),
            ]);
        }

        return [$confirmed, ['title' => 'Membership active', 'message' => 'Payment received for '.$plan->name, 'data' => ['membership_id' => $membership->id]]];
    }
}

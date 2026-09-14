<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\Payment;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

class SettleCheckout
{
    public function apply(array $session): Payment
    {
        $attributes = $session['attributes'];
        $payment = Payment::where('provider', 'paymongo')->where('reference', $attributes['reference_number'] ?? '')->firstOrFail();
        abort_unless(($attributes['livemode'] ?? null) === str_starts_with((string) config('services.paymongo.secret_key'), 'sk_live_'), 422, 'Payment mode mismatch.');

        return DB::transaction(function () use ($payment, $session, $attributes) {
            $booking = Booking::findOrFail($payment->booking_id);
            Court::whereKey($booking->court_id)->lockForUpdate()->firstOrFail();
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_if($payment->provider_reference && $payment->provider_reference !== $session['id'], 422, 'Checkout reference mismatch.');
            $payment->provider_reference = $session['id'];
            if (in_array($payment->status, ['paid', 'paid_review'], true)) {
                return $payment;
            }
            $paid = collect($attributes['payments'] ?? [])->filter(fn ($p) => data_get($p, 'attributes.status') === 'paid');
            if ($paid->isNotEmpty()) {
                $validAmount = $paid->sum('attributes.amount') === (int) round((float) $payment->amount * 100)
                    && $paid->every(fn ($p) => data_get($p, 'attributes.currency') === $payment->currency);
                $canConfirm = $validAmount && $booking->status === 'reserved' && $booking->expires_at?->isFuture()
                    && $booking->starts_at->isFuture() && (float) $booking->amount === (float) $payment->amount;
                $payment->status = $canConfirm ? 'paid' : 'paid_review';
                $payment->paid_at = now();
                if ($canConfirm) {
                    $booking->update(['status' => 'confirmed', 'expires_at' => null]);
                }
                UserNotification::create(['tenant_id' => $payment->tenant_id, 'user_id' => $payment->user_id,
                    'channel' => 'in_app', 'type' => 'payment', 'title' => $canConfirm ? 'Booking confirmed' : 'Payment needs review',
                    'message' => $canConfirm ? 'Payment received for '.$booking->reference : 'Payment was received but the reservation could not be confirmed. Contact your facility for resolution or a refund.',
                    'data' => ['payment_id' => $payment->id, 'booking_id' => $booking->id]]);
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
}

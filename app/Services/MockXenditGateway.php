<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Membership;
use App\Models\MembershipCard;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MockXenditGateway
{
    public function enabled(): bool
    {
        return (bool) config('services.xendit.mock_enabled')
            && app()->environment(['local', 'testing']);
    }

    /** @return array{id: string, checkout_url: string} */
    public function create(Payment $payment): array
    {
        abort_unless($this->enabled(), 503, 'Xendit is not configured. Enable the local mock only for development.');

        return [
            'id' => 'xendit_mock_'.$payment->id,
            'checkout_url' => 'https://checkout.xendit.test/invoices/'.$payment->reference,
        ];
    }

    public function complete(Payment $payment): Payment
    {
        abort_unless($this->enabled(), 404);

        return DB::transaction(function () use ($payment): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (in_array($payment->status, ['paid', 'paid_review'], true)) {
                return $payment;
            }

            $confirmed = false;
            $data = ['payment_id' => $payment->id];
            $title = 'Payment needs review';
            $message = 'Payment was received but could not be completed. Contact your facility for resolution or a refund.';
            if ($payment->booking_id) {
                $booking = Booking::whereKey($payment->booking_id)->lockForUpdate()->firstOrFail();
                $confirmed = $booking->status === 'reserved' && $booking->expires_at?->isFuture() && $booking->starts_at->isFuture();
                if ($confirmed) {
                    $booking->update(['status' => 'confirmed', 'expires_at' => null]);
                }
                $title = $confirmed ? 'Booking confirmed' : $title;
                $message = $confirmed ? 'Mock Xendit payment received for '.$booking->reference : $message;
                $data['booking_id'] = $booking->id;
            } elseif ($payment->membership_id) {
                $membership = Membership::whereKey($payment->membership_id)->lockForUpdate()->firstOrFail();
                $plan = MembershipPlan::whereKey($membership->membership_plan_id)->lockForUpdate()->firstOrFail();
                $confirmed = $membership->status === 'pending' && $plan->is_active;
                if ($confirmed) {
                    $membership->update(['status' => 'active']);
                    MembershipCard::firstOrCreate(['membership_id' => $membership->id], [
                        'tenant_id' => $membership->tenant_id,
                        'card_number' => 'MC-'.Str::upper(Str::random(12)),
                        'qr_code' => Str::upper(Str::random(24)),
                    ]);
                }
                $title = $confirmed ? 'Membership active' : $title;
                $message = $confirmed ? 'Mock Xendit payment received for '.$plan->name : $message;
                $data['membership_id'] = $membership->id;
            }

            $payment->update(['status' => $confirmed ? 'paid' : 'paid_review', 'paid_at' => now()]);
            UserNotification::create([
                'tenant_id' => $payment->tenant_id,
                'user_id' => $payment->user_id,
                'channel' => 'in_app',
                'type' => 'payment',
                'title' => $title,
                'message' => $message,
                'data' => $data,
            ]);

            return $payment->fresh();
        });
    }

    public function refund(Payment $payment, PaymentRefund $refund): PaymentRefund
    {
        abort_unless($this->enabled(), 404);
        abort_unless($payment->provider === 'xendit', 422, 'Only mock Xendit refunds can be completed automatically.');

        $refund->update(['status' => 'completed']);

        return $refund->fresh();
    }
}

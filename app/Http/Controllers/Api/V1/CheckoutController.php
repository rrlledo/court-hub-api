<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCheckoutWebhook;
use App\Models\Booking;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Services\MockXenditGateway;
use App\Services\PayMongoCheckout;
use App\Services\SettleCheckout;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function create(Request $request, PayMongoCheckout $payMongo, MockXenditGateway $xendit)
    {
        $data = $request->validate(['booking_id' => ['nullable', 'integer'], 'membership_id' => ['nullable', 'integer'], 'provider' => ['required', 'in:paymongo,xendit'], 'method' => ['required', 'in:gcash,maya,card']]);
        abort_unless((bool) ($data['booking_id'] ?? null) xor (bool) ($data['membership_id'] ?? null), 422, 'Choose one booking or membership to pay for.');
        if ($data['provider'] === 'paymongo') {
            abort_unless(filled(config('services.paymongo.secret_key')) && filled(config('services.paymongo.webhook_secret')) && filled(config('services.paymongo.return_url')), 503, 'Online payments are not configured.');
            abort_unless(filter_var(config('services.paymongo.return_url'), FILTER_VALIDATE_URL) && parse_url(config('services.paymongo.return_url'), PHP_URL_SCHEME) === 'https', 503, 'Payment return URL must be HTTPS.');
        } else {
            abort_unless($xendit->enabled(), 503, 'Xendit is not configured. Enable the local mock only for development.');
        }
        [$payment, $create] = DB::transaction(function () use ($request, $data) {
            if (isset($data['booking_id'])) {
                $booking = Booking::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($data['booking_id']);
                abort_unless($booking->user_id === $request->user()->id, 403, 'You can pay only for your own bookings.');
                abort_unless($booking->status === 'reserved' && $booking->expires_at?->isFuture() && $booking->starts_at->isFuture(), 422, 'This booking no longer accepts payment.');
                abort_unless($booking->currency === 'PHP' && (float) $booking->amount >= 1, 422, 'This booking cannot use online checkout.');
                $existing = Payment::where('booking_id', $booking->id)->where('provider', $data['provider'])->whereNotIn('status', ['rejected'])->latest('id')->first();
                if ($existing) {
                    return [$existing, false];
                }

                return [Payment::create(['tenant_id' => $booking->tenant_id, 'booking_id' => $booking->id, 'user_id' => $booking->user_id,
                    'amount' => $booking->amount, 'currency' => $booking->currency, 'provider' => $data['provider'], 'method' => $data['method'],
                    'reference' => 'PAY-'.Str::uuid(), 'invoice_number' => 'INV-'.Str::uuid(), 'status' => 'creating']), true];
            }

            $membership = Membership::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($data['membership_id']);
            abort_unless($membership->user_id === $request->user()->id, 403, 'You can pay only for your own membership.');
            abort_unless($membership->status === 'pending', 422, 'This membership no longer accepts payment.');
            $plan = MembershipPlan::where('tenant_id', $membership->tenant_id)->where('is_active', true)->findOrFail($membership->membership_plan_id);
            abort_unless($plan->currency === 'PHP' && (float) $plan->price >= 1, 422, 'This membership cannot use online checkout.');
            $existing = Payment::where('membership_id', $membership->id)->where('provider', $data['provider'])->whereNotIn('status', ['rejected'])->latest('id')->first();
            if ($existing) {
                return [$existing, false];
            }

            return [Payment::create(['tenant_id' => $membership->tenant_id, 'membership_id' => $membership->id, 'user_id' => $membership->user_id,
                'amount' => $plan->price, 'currency' => $plan->currency, 'provider' => $data['provider'], 'method' => $data['method'],
                'reference' => 'PAY-'.Str::uuid(), 'invoice_number' => 'INV-'.Str::uuid(), 'status' => 'creating']), true];
        });
        if ($create) {
            try {
                $session = $data['provider'] === 'paymongo' ? $payMongo->create($payment) : $xendit->create($payment);
                $url = $data['provider'] === 'paymongo' ? data_get($session, 'attributes.checkout_url') : data_get($session, 'checkout_url');
                $host = $data['provider'] === 'paymongo' ? 'checkout.paymongo.com' : 'checkout.xendit.test';
                abort_unless(is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === $host, 502, 'Provider returned an invalid checkout URL.');
                DB::transaction(function () use ($payment, $session, $url, $data) {
                    $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                    $locked->update(['provider_reference' => $session['id'], 'provider_payload' => ['checkout_url' => $url, 'mock' => $data['provider'] === 'xendit'],
                        'status' => $locked->status === 'creating' ? 'pending' : $locked->status]);
                });
            } catch (RequestException $e) {
                if ($e->response->status() >= 400 && $e->response->status() < 500) {
                    $payment->update(['status' => 'rejected']);
                }
                abort(502, 'Checkout could not be created. Check payment status before retrying.');
            } catch (ConnectionException $e) {
                abort(503, 'Checkout creation could not be verified. Check payment status; do not submit another payment.');
            }
        }

        return response()->json(['data' => $this->result($payment->fresh())], $create ? 201 : 200);
    }

    private function result(Payment $payment): array
    {
        return ['payment' => $payment->only(['id', 'booking_id', 'membership_id', 'reference', 'invoice_number', 'status', 'amount', 'currency', 'paid_at']),
            'checkout_url' => data_get($payment->provider_payload, 'checkout_url'),
            'attempt_failed' => (bool) data_get($payment->provider_payload, 'attempt_failed', false),
            'booking' => Booking::find($payment->booking_id)?->only(['id', 'status', 'expires_at']),
            'membership' => Membership::find($payment->membership_id)?->only(['id', 'status', 'starts_on', 'ends_on'])];
    }

    public function status(Request $request, int $booking, PayMongoCheckout $provider, SettleCheckout $settler)
    {
        $model = Booking::where('tenant_id', $request->user()->tenant_id)->findOrFail($booking);
        abort_unless($model->user_id === $request->user()->id, 403);
        $payment = Payment::where('booking_id', $booking)->latest('id')->first();
        if (! $payment) {
            return response()->json(['data' => ['payment' => null, 'checkout_url' => null, 'booking' => $model->only(['id', 'status', 'expires_at'])]]);
        }
        if ($payment->provider === 'paymongo' && $payment->provider_reference && ! in_array($payment->status, ['paid', 'paid_review'], true)) {
            try {
                $payment = $provider->reconcile($payment);
            } catch (RequestException|ConnectionException $e) {
                abort(503, 'Payment verification is temporarily unavailable. Try checking again.');
            }
        }

        return response()->json(['data' => $this->result($payment)]);
    }

    public function completeMockXendit(Request $request, int $payment, MockXenditGateway $xendit)
    {
        $model = Payment::where('tenant_id', $request->user()->tenant_id)->findOrFail($payment);
        abort_unless($model->user_id === $request->user()->id, 403);
        abort_unless($model->provider === 'xendit', 422, 'This is not an Xendit payment.');

        return response()->json(['data' => $this->result($xendit->complete($model))]);
    }

    public function webhook(Request $request, string $provider)
    {
        abort_unless($provider === 'paymongo', 404);
        $secret = config('services.paymongo.webhook_secret');
        abort_unless(filled($secret) && filled(config('services.paymongo.secret_key')), 503);
        $parts = [];
        foreach (explode(',', (string) $request->header('Paymongo-Signature')) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        $timestamp = $parts['t'] ?? '';
        $mode = str_starts_with(config('services.paymongo.secret_key'), 'sk_live_') ? 'li' : 'te';
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300 && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $parts[$mode] ?? ''), 401, 'Invalid webhook signature.');
        $type = $request->input('data.attributes.type', $request->input('data.type'));
        if ($type !== 'checkout_session.payment.paid') {
            return response()->json(['ignored' => true]);
        }
        $id = $request->input('data.attributes.data.id', $request->input('data.data.id'));
        abort_unless(is_string($id) && preg_match('/^cs_[a-zA-Z0-9]+$/', $id), 422);
        // A checkout is single-use: duplicates converge on the same settlement record.
        $event = PaymentWebhookEvent::firstOrCreate(['event_id' => 'paymongo:paid:'.$id], ['provider' => 'paymongo', 'event_type' => $type, 'payload' => ['checkout_id' => $id]]);
        if (! $event->processed_at) {
            ProcessCheckoutWebhook::dispatch($event->id);
        }

        return response()->json(['accepted' => true], 202);
    }
}

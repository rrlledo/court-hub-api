<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

class PayMongoCheckout
{
    private function client()
    {
        $key = config('services.paymongo.secret_key');
        abort_unless(filled($key), 503, 'Online payments are not configured.');

        return Http::withBasicAuth($key, '')->acceptJson()->timeout(20)->connectTimeout(5);
    }

    public function create(Payment $payment): array
    {
        $return = config('services.paymongo.return_url');
        abort_unless(filter_var($return, FILTER_VALIDATE_URL) && parse_url($return, PHP_URL_SCHEME) === 'https', 503, 'Payment return URL must be HTTPS.');

        return $this->client()->post('https://api.paymongo.com/v1/checkout_sessions', ['data' => ['attributes' => [
            'line_items' => [['name' => 'Court reservation '.$payment->reference, 'quantity' => 1,
                'amount' => (int) round((float) $payment->amount * 100), 'currency' => $payment->currency]],
            'payment_method_types' => [$payment->method === 'maya' ? 'paymaya' : $payment->method],
            'reference_number' => $payment->reference, 'send_email_receipt' => true,
            'show_line_items' => true, 'success_url' => $return, 'cancel_url' => $return,
        ]]])->throw()->json('data');
    }

    public function retrieve(string $id): array
    {
        abort_unless(preg_match('/^cs_[a-zA-Z0-9]+$/', $id), 422, 'Invalid checkout reference.');

        return $this->client()->get('https://api.paymongo.com/v1/checkout_sessions/'.$id)->throw()->json('data');
    }

    public function reconcile(Payment $payment): Payment
    {
        $session = $this->retrieve($payment->provider_reference);
        abort_unless(($session['id'] ?? null) === $payment->provider_reference && data_get($session, 'attributes.reference_number') === $payment->reference, 422, 'Checkout identity mismatch.');
        $settler = app(SettleCheckout::class);
        $payment = $settler->apply($session);
        $booking = Booking::findOrFail($payment->booking_id);
        if (! in_array($payment->status, ['paid', 'paid_review', 'expired'], true)
            && ($booking->status !== 'reserved' || ! $booking->expires_at?->isFuture() || ! $booking->starts_at->isFuture())) {
            $this->client()->post('https://api.paymongo.com/v1/checkout_sessions/'.$payment->provider_reference.'/expire')->throw();
            $payment = $settler->apply($this->retrieve($payment->provider_reference));
        }

        return $payment;
    }
}

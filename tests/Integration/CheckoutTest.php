<?php

namespace Tests\Integration;

use App\Jobs\ProcessCheckoutWebhook;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Court;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PayMongoCheckout;
use App\Services\SettleCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paymongo.secret_key' => 'sk_test_example', 'services.paymongo.webhook_secret' => 'webhook-test', 'services.paymongo.return_url' => 'https://court.example/payments/return']);
        Http::preventStrayRequests();
        $tenant = Tenant::create(['name' => 'Sports', 'slug' => 'sports']);
        $org = Organization::create(['tenant_id' => $tenant->id, 'name' => 'Org']);
        $facility = Facility::create(['tenant_id' => $tenant->id, 'organization_id' => $org->id, 'name' => 'Facility']);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'facility_id' => $facility->id, 'name' => 'Branch']);
        $court = Court::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Court', 'base_price' => 500, 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user);
        $this->booking = Booking::create(['tenant_id' => $tenant->id, 'court_id' => $court->id, 'user_id' => $user->id, 'reference' => 'CH-TEST', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'expires_at' => now()->addMinutes(10), 'amount' => 500, 'currency' => 'PHP', 'status' => 'reserved']);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions' => Http::response(['data' => ['id' => 'cs_test', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test']]])]);
    }

    private function checkout()
    {
        return $this->postJson('/api/v1/payments/intents', ['booking_id' => $this->booking->id, 'amount' => 1, 'provider' => 'paymongo', 'method' => 'gcash']);
    }

    private function paid(int $amount = 50000): array
    {
        return ['id' => 'cs_test', 'attributes' => ['reference_number' => Payment::firstOrFail()->reference,
            'livemode' => false, 'payments' => [['attributes' => ['status' => 'paid', 'amount' => $amount, 'currency' => 'PHP']]]]];
    }

    public function test_server_price_and_reused_checkout(): void
    {
        $this->checkout()->assertCreated()->assertJsonPath('data.payment.amount', '500.00');
        $this->checkout()->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['data']['attributes']['line_items'][0]['amount'] === 50000);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_status_confirms_only_verified_payment_and_is_idempotent(): void
    {
        $this->checkout()->assertCreated();
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_test' => Http::response(['data' => $this->paid()])]);
        $this->getJson('/api/v1/bookings/'.$this->booking->id.'/payment-status')->assertOk()->assertJsonPath('data.payment.status', 'paid')->assertJsonPath('data.booking.status', 'confirmed');
        app(SettleCheckout::class)->apply($this->paid());
        $this->assertDatabaseCount('user_notifications', 1);
    }

    public function test_another_player_cannot_pay_or_read_status(): void
    {
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->booking->tenant_id]));
        $this->checkout()->assertForbidden();
        $this->getJson('/api/v1/bookings/'.$this->booking->id.'/payment-status')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_late_payment_is_recorded_without_reclaiming_expired_court(): void
    {
        $this->checkout()->assertCreated();
        $this->booking->update(['status' => 'expired', 'expires_at' => now()->subMinute()]);
        $payment = app(SettleCheckout::class)->apply($this->paid());
        $this->assertSame('paid_review', $payment->status);
        $this->assertSame('expired', $this->booking->fresh()->status);
    }

    public function test_amount_mismatch_never_confirms_booking(): void
    {
        $this->checkout()->assertCreated();
        $this->assertSame('paid_review', app(SettleCheckout::class)->apply($this->paid(100))->status);
        $this->assertSame('reserved', $this->booking->fresh()->status);
    }

    public function test_webhook_is_signed_queued_and_duplicate_safe(): void
    {
        Queue::fake();
        $this->checkout()->assertCreated();
        $body = json_encode(['data' => ['attributes' => ['type' => 'checkout_session.payment.paid', 'data' => ['id' => 'cs_test']]]]);
        $this->call('POST', '/api/v1/payments/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertUnauthorized();
        $timestamp = (string) time();
        $signature = 't='.$timestamp.',te='.hash_hmac('sha256', $timestamp.'.'.$body, 'webhook-test');
        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/api/v1/payments/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => $signature], $body)->assertStatus(202);
        }
        Queue::assertPushed(ProcessCheckoutWebhook::class);
        $this->assertDatabaseCount('payment_webhook_events', 1);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_test' => Http::response(['data' => $this->paid()])]);
        $job = new ProcessCheckoutWebhook(PaymentWebhookEvent::firstOrFail()->id);
        $job->handle(app(PayMongoCheckout::class), app(SettleCheckout::class));
        $job->handle(app(PayMongoCheckout::class), app(SettleCheckout::class));
        $this->assertSame('confirmed', $this->booking->fresh()->status);
        $this->assertDatabaseCount('user_notifications', 1);
    }

    public function test_missing_configuration_and_expired_hold_fail_closed(): void
    {
        $this->booking->update(['expires_at' => now()->subMinute()]);
        $this->checkout()->assertUnprocessable();
        config(['services.paymongo.webhook_secret' => null]);
        $this->checkout()->assertStatus(503);
        $this->postJson('/api/v1/payments/webhooks/paymongo', [])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_expiry_closes_provider_checkout(): void
    {
        $this->checkout()->assertCreated();
        $this->booking->update(['status' => 'cancelled']);
        $session = $this->paid();
        $session['attributes']['payments'] = [];
        $session['attributes']['status'] = 'active';
        $expired = $session;
        $expired['attributes']['status'] = 'expired';
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_test' => Http::sequence()->push(['data' => $session])->push(['data' => $expired]),
            'https://api.paymongo.com/v1/checkout_sessions/cs_test/expire' => Http::response(['data' => $expired])]);
        $this->getJson('/api/v1/bookings/'.$this->booking->id.'/payment-status')->assertOk()->assertJsonPath('data.payment.status', 'expired');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/expire'));
    }

    public function test_timeout_never_creates_a_second_checkout(): void
    {
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions' => Http::failedConnection()]);
        $this->checkout()->assertStatus(503);
        $this->checkout()->assertOk()->assertJsonPath('data.payment.status', 'creating');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_failed_attempt_stays_retryable_without_confirming(): void
    {
        $this->checkout()->assertCreated();
        $session = $this->paid();
        $session['attributes']['payments'] = [];
        $session['attributes']['payment_intent']['attributes']['last_payment_error'] = ['code' => 'declined'];
        $payment = app(SettleCheckout::class)->apply($session);
        $this->assertSame('pending', $payment->status);
        $this->assertTrue($payment->provider_payload['attempt_failed']);
        $this->assertSame('reserved', $this->booking->fresh()->status);
    }
}

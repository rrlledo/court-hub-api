<?php

namespace Tests\Integration;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlayerMembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    private MembershipPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paymongo.secret_key' => 'sk_test_example', 'services.paymongo.webhook_secret' => 'webhook-test', 'services.paymongo.return_url' => 'https://court.example/payments/return']);
        Http::preventStrayRequests();
        $tenant = Tenant::create(['name' => 'Sports', 'slug' => 'sports']);
        Organization::create(['tenant_id' => $tenant->id, 'name' => 'Org']);
        $this->player = User::factory()->create(['tenant_id' => $tenant->id, 'email_verified_at' => now()]);
        Role::findOrCreate('player', 'web');
        $this->player->assignRole('player');
        $this->plan = MembershipPlan::create(['tenant_id' => $tenant->id, 'name' => 'Gold', 'billing_period' => 'monthly', 'price' => 1200, 'currency' => 'PHP', 'duration_days' => 30, 'session_count' => 8]);
        MembershipPlan::create(['tenant_id' => $tenant->id, 'name' => 'Hidden', 'billing_period' => 'monthly', 'price' => 900, 'currency' => 'PHP', 'duration_days' => 30, 'is_active' => false]);
        Sanctum::actingAs($this->player);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions' => Http::response(['data' => ['id' => 'cs_membership', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_membership']]])]);
    }

    public function test_player_can_buy_membership_and_receives_card_after_verified_payment(): void
    {
        $this->getJson('/api/v1/player/membership-plans')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->plan->id);
        $membership = $this->postJson('/api/v1/player/memberships', ['membership_plan_id' => $this->plan->id])->assertCreated()->assertJsonPath('data.status', 'pending');
        $id = $membership->json('data.id');
        $this->postJson('/api/v1/payments/intents', ['membership_id' => $id, 'provider' => 'paymongo', 'method' => 'gcash'])->assertCreated()->assertJsonPath('data.payment.membership_id', $id);
        Http::assertSent(fn ($request) => $request['data']['attributes']['line_items'][0]['name'] === 'Membership Gold');

        $payment = Payment::firstOrFail();
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_membership' => Http::response(['data' => [
            'id' => 'cs_membership',
            'attributes' => ['reference_number' => $payment->reference, 'livemode' => false,
                'payments' => [['attributes' => ['status' => 'paid', 'amount' => 120000, 'currency' => 'PHP']]]],
        ]])]);
        $this->getJson('/api/v1/player/memberships/'.$id.'/payment-status')->assertOk()
            ->assertJsonPath('data.payment.status', 'paid')->assertJsonPath('data.membership.status', 'active')
            ->assertJsonPath('data.membership.plan.name', 'Gold');
        $this->getJson('/api/v1/player/memberships/'.$id)->assertOk()->assertJsonPath('data.card.is_active', true);
    }

    public function test_player_can_prepare_a_renewal_but_unverified_players_cannot_purchase(): void
    {
        $active = $this->postJson('/api/v1/player/memberships', ['membership_plan_id' => $this->plan->id])->assertCreated()->json('data');
        $this->player->forceFill(['email_verified_at' => null])->save();
        Sanctum::actingAs($this->player->fresh());
        $this->postJson('/api/v1/player/memberships', ['membership_plan_id' => $this->plan->id])->assertForbidden();

        $this->player->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($this->player->fresh());
        $membership = Membership::findOrFail($active['id']);
        $membership->update(['status' => 'active']);
        $this->postJson('/api/v1/player/memberships/'.$membership->id.'/renew')->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.membership_plan_id', $this->plan->id);
    }
}

<?php

namespace Tests\Integration;

use App\Models\Branch;
use App\Models\Court;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyPlayerEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlayerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => 'Sports', 'slug' => 'sports']);
        $organization = Organization::create(['tenant_id' => $tenant->id, 'name' => 'Org']);
        $this->facility = Facility::create(['tenant_id' => $tenant->id, 'organization_id' => $organization->id, 'name' => 'Open Courts', 'address' => 'Makati']);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'facility_id' => $this->facility->id, 'name' => 'Main']);
        Court::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Court 1', 'status' => 'active', 'base_price' => 500]);
        $this->owner = User::factory()->create(['tenant_id' => $tenant->id]);
        Role::findOrCreate('court-owner', 'web');
        $this->owner->assignRole('court-owner');
    }

    public function test_owner_can_open_signup_only_for_a_bookable_facility(): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson('/api/v1/facilities/'.$this->facility->id.'/registration', ['registration_open' => true])->assertOk()->assertJsonPath('data.registration_open', true);
        $this->getJson('/api/v1/registration/facilities')->assertOk()->assertJsonPath('data.0.id', $this->facility->id);
        $closed = Facility::create(['tenant_id' => $this->owner->tenant_id, 'organization_id' => $this->facility->organization_id, 'name' => 'No courts']);
        $this->patchJson('/api/v1/facilities/'.$closed->id.'/registration', ['registration_open' => true])->assertUnprocessable();
        $this->getJson('/api/v1/registration/facilities?search=No')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_player_registration_is_facility_scoped_and_requires_verification_for_booking(): void
    {
        $this->facility->update(['registration_open' => true]);
        Notification::fake();
        $response = $this->postJson('/api/v1/auth/register-player', ['facility_id' => $this->facility->id, 'name' => 'New Player', 'email' => 'PLAYER@EXAMPLE.TEST', 'password' => 'PlayerPassword123!', 'password_confirmation' => 'PlayerPassword123!', 'tenant_id' => 999, 'roles' => ['court-owner']]);
        $response->assertUnprocessable();
        $response = $this->postJson('/api/v1/auth/register-player', ['facility_id' => $this->facility->id, 'name' => 'New Player', 'email' => 'PLAYER@EXAMPLE.TEST', 'password' => 'PlayerPassword123!', 'password_confirmation' => 'PlayerPassword123!'])->assertCreated()->assertJsonPath('data.user.home_facility_id', $this->facility->id);
        $player = User::where('email', 'player@example.test')->firstOrFail();
        $this->assertSame($this->facility->tenant_id, $player->tenant_id);
        $this->assertTrue($player->hasRole('player'));
        Notification::assertSentTo($player, VerifyPlayerEmail::class);
        Sanctum::actingAs($player);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.facility.id', $this->facility->id);
        $court = $this->facility->branches()->firstOrFail()->courts()->firstOrFail();
        $booking = ['court_id' => $court->id, 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHour()->toIso8601String()];
        $this->postJson('/api/v1/bookings', $booking)->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), ['id' => $player->id, 'hash' => sha1($player->getEmailForVerification())]);
        $this->get($url)->assertOk();
        $this->assertNotNull($player->fresh()->email_verified_at);
        Sanctum::actingAs($player->fresh());
        $this->postJson('/api/v1/bookings', $booking)->assertCreated();
    }

    public function test_closed_facility_cannot_register_player_and_email_is_unique(): void
    {
        $payload = ['facility_id' => $this->facility->id, 'name' => 'New Player', 'email' => 'new@example.test', 'password' => 'PlayerPassword123!', 'password_confirmation' => 'PlayerPassword123!'];
        $this->postJson('/api/v1/auth/register-player', $payload)->assertNotFound();
        $this->facility->update(['registration_open' => true]);
        $this->postJson('/api/v1/auth/register-player', $payload)->assertCreated();
        $this->postJson('/api/v1/auth/register-player', $payload)->assertUnprocessable();
    }
}

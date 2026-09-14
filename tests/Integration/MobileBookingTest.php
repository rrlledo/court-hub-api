<?php

namespace Tests\Integration;

use App\Models\{Tenant, Organization, Facility, Branch, Court, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_can_discover_and_reserve_only_tenant_courts(): void
    {
        $tenant = Tenant::create(['name' => 'Sports', 'slug' => 'sports']);
        $organization = Organization::create(['tenant_id' => $tenant->id, 'name' => 'Org']);
        $facility = Facility::create(['tenant_id' => $tenant->id, 'organization_id' => $organization->id, 'name' => 'Facility']);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'facility_id' => $facility->id, 'name' => 'Branch']);
        $court = Court::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Court', 'base_price' => 500, 'status' => 'active']);
        $this->getJson('/api/v1/booking-facilities')->assertUnauthorized();
        $player = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($player);
        $this->getJson('/api/v1/booking-facilities')->assertOk()->assertJsonPath('data.0.id', $facility->id);
        $this->postJson('/api/v1/facilities', ['name' => 'Forbidden'])->assertForbidden();
        $payload = ['court_id' => $court->id, 'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(), 'ends_at' => now()->addDay()->setTime(11, 0)->toIso8601String()];
        $reservation = $this->postJson('/api/v1/bookings', $payload)->assertCreated()->assertJsonPath('data.status', 'reserved');
        $this->postJson('/api/v1/bookings', $payload)->assertConflict();
        $bookingId = $reservation->json('data.id');
        $move = ['starts_at' => now()->addDay()->setTime(12, 0)->toIso8601String(), 'ends_at' => now()->addDay()->setTime(13, 0)->toIso8601String()];
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $tenant->id]));
        $this->patchJson('/api/v1/bookings/'.$bookingId.'/reschedule', $move)->assertForbidden();
        $this->postJson('/api/v1/bookings/'.$bookingId.'/cancel')->assertForbidden();
        Sanctum::actingAs($player);
        $this->patchJson('/api/v1/bookings/'.$bookingId.'/reschedule', $move)->assertOk()->assertJsonPath('data.expires_at', $reservation->json('data.expires_at'));
        $this->patchJson('/api/v1/bookings/'.$bookingId.'/reschedule', array_merge($move, ['ends_at' => now()->addDay()->setTime(14, 0)->toIso8601String()]))->assertUnprocessable();
        $this->postJson('/api/v1/bookings', $payload)->assertCreated();
        $this->patchJson('/api/v1/bookings/'.$bookingId.'/reschedule', $payload)->assertConflict();
        \App\Models\CourtMaintenance::create(['tenant_id' => $tenant->id, 'court_id' => $court->id, 'starts_at' => now()->addDay()->setTime(14, 0), 'ends_at' => now()->addDay()->setTime(16, 0), 'status' => 'scheduled', 'reason' => 'Repairs']);
        $this->patchJson('/api/v1/bookings/'.$bookingId.'/reschedule', ['starts_at' => now()->addDay()->setTime(14, 0)->toIso8601String(), 'ends_at' => now()->addDay()->setTime(15, 0)->toIso8601String()])->assertConflict();
        $this->postJson('/api/v1/bookings/'.$bookingId.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/bookings/'.$bookingId.'/cancel')->assertUnprocessable();
        $this->patchJson('/api/v1/bookings/'.$bookingId.'/reschedule', $move)->assertUnprocessable();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $other->id]));
        $this->getJson('/api/v1/booking-facilities')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/bookings', $payload)->assertNotFound();
        $this->patchJson('/api/v1/bookings/'.$bookingId.'/reschedule', $move)->assertNotFound();
    }
}


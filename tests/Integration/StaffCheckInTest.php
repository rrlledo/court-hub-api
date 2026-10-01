<?php

namespace Tests\Integration;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Court;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\MembershipCard;
use App\Models\MembershipPlan;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffCheckInTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Sports', 'slug' => 'sports']);
        $organization = Organization::create(['tenant_id' => $this->tenant->id, 'name' => 'Org']);
        $facility = Facility::create(['tenant_id' => $this->tenant->id, 'organization_id' => $organization->id, 'name' => 'Facility']);
        $branch = Branch::create(['tenant_id' => $this->tenant->id, 'facility_id' => $facility->id, 'name' => 'Main']);
        Court::create(['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'name' => 'Court 1', 'base_price' => 500, 'status' => 'active']);
        $this->staff = User::factory()->create(['tenant_id' => $this->tenant->id]);
        Role::findOrCreate('front-desk', 'web');
        $this->staff->assignRole('front-desk');
        Sanctum::actingAs($this->staff);
    }

    public function test_staff_scans_confirmed_booking_once_and_gets_idempotent_result(): void
    {
        $player = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $court = Court::firstOrFail();
        $booking = Booking::create(['tenant_id' => $this->tenant->id, 'court_id' => $court->id, 'user_id' => $player->id,
            'reference' => 'CH-QR', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'amount' => 500,
            'currency' => 'PHP', 'status' => 'confirmed']);
        $booking->update(['qr_code' => 'BOOKING-QR']);

        $this->postJson('/api/v1/check-ins/scan', ['qr_code' => 'booking-qr'])->assertCreated()
            ->assertJsonPath('data.type', 'booking')->assertJsonPath('data.member.name', $player->name);
        $this->postJson('/api/v1/check-ins/scan', ['qr_code' => 'BOOKING-QR'])->assertOk()
            ->assertJsonPath('data.already_checked_in', true);
        $this->assertDatabaseCount('check_ins', 1);
    }

    public function test_staff_scans_active_membership_card_and_records_audit_event(): void
    {
        $player = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $plan = MembershipPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'Gold', 'billing_period' => 'monthly', 'price' => 1200, 'duration_days' => 30]);
        $membership = Membership::create(['tenant_id' => $this->tenant->id, 'membership_plan_id' => $plan->id, 'user_id' => $player->id,
            'starts_on' => today()->subDay(), 'ends_on' => today()->addDay(), 'status' => 'active']);
        MembershipCard::create(['tenant_id' => $this->tenant->id, 'membership_id' => $membership->id, 'card_number' => 'MC-1', 'qr_code' => 'MEMBER-QR']);

        $this->postJson('/api/v1/check-ins/scan', ['qr_code' => 'member-qr'])->assertCreated()
            ->assertJsonPath('data.type', 'membership')->assertJsonPath('data.membership.plan.name', 'Gold')
            ->assertJsonPath('data.member.name', $player->name);
        $this->assertDatabaseHas('membership_check_ins', ['membership_id' => $membership->id, 'checked_in_by' => $this->staff->id]);
        $membership->update(['status' => 'frozen']);
        $this->postJson('/api/v1/check-ins/scan', ['qr_code' => 'MEMBER-QR'])->assertUnprocessable();
    }
}

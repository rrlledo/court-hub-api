<?php

namespace Tests\Integration;

use App\Models\Branch;
use App\Models\CoachProfile;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdvancedNonProductionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulated_advanced_workflows_are_available_without_provider_accounts(): void
    {
        foreach (['court-owner', 'coach', 'event-organizer', 'player', 'super-admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $tenant = Tenant::create(['name' => 'Demo', 'slug' => 'advanced-demo']);
        $organization = Organization::create(['tenant_id' => $tenant->id, 'name' => 'Demo Org']);
        $facility = Facility::create(['tenant_id' => $tenant->id, 'organization_id' => $organization->id, 'name' => 'Demo Facility', 'registration_open' => true]);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'facility_id' => $facility->id, 'name' => 'Demo Branch']);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $owner->assignRole(['court-owner', 'super-admin']);
        $coachUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $coachUser->assignRole('coach');
        $coach = CoachProfile::create(['tenant_id' => $tenant->id, 'user_id' => $coachUser->id, 'name' => $coachUser->name, 'email' => $coachUser->email, 'hourly_rate' => 500, 'is_active' => true]);
        $organizer = User::factory()->create(['tenant_id' => $tenant->id]);
        $organizer->assignRole('event-organizer');
        $tournament = Tournament::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Demo Cup', 'format' => 'team', 'starts_at' => now()->addDay(), 'entry_fee' => 200, 'status' => 'open']);

        $this->postJson('/api/v1/auth/social/google', ['mock_subject' => 'local-google-1', 'email' => 'social-player@example.test', 'name' => 'Social Player', 'facility_id' => $facility->id])->assertCreated()->assertJsonPath('data.mock', true);
        $player = User::where('email', 'social-player@example.test')->firstOrFail();

        Sanctum::actingAs($owner);
        $student = $this->postJson('/api/v1/coaches/'.$coach->id.'/students', ['user_id' => $player->id, 'revenue_share_percent' => 35])->assertCreated()->assertJsonPath('data.player.id', $player->id)->json('data.id');
        $this->getJson('/api/v1/coaches/'.$coach->id.'/students')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/payments/settlements')->assertOk()->assertJsonPath('data.simulated', true);
        $invoice = $this->postJson('/api/v1/super-admin/tenants/'.$tenant->id.'/subscription-invoices', ['amount' => 1200, 'status' => 'overdue'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/super-admin/tenants/'.$tenant->id.'/subscription-invoices/'.$invoice.'/settle')->assertOk()->assertJsonPath('data.status', 'paid');
        $this->deleteJson('/api/v1/coaches/'.$coach->id.'/students/'.$student)->assertNoContent();

        Sanctum::actingAs($organizer);
        $team = $this->postJson('/api/v1/organizer/tournaments/'.$tournament->id.'/teams', ['name' => 'Local Team'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/organizer/tournaments/'.$tournament->id.'/teams/'.$team.'/members', ['user_id' => $player->id])->assertCreated();
        $registration = $this->getJson('/api/v1/organizer/tournaments/'.$tournament->id)->assertOk()->json('data.registrations.0.id');
        $this->postJson('/api/v1/organizer/tournaments/'.$tournament->id.'/registrations/'.$registration.'/check-in')->assertOk()->assertJsonPath('data.checked_in_by', $organizer->id);

        Sanctum::actingAs($coachUser);
        $this->getJson('/api/v1/coach/students')->assertOk()->assertJsonCount(0, 'data');
    }
}

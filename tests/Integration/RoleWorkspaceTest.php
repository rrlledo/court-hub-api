<?php

namespace Tests\Integration;

use App\Models\Branch;
use App\Models\CoachingSession;
use App\Models\CoachProfile;
use App\Models\Court;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Sports', 'slug' => 'sports']);
        $organization = Organization::create(['tenant_id' => $this->tenant->id, 'name' => 'Org']);
        $facility = Facility::create(['tenant_id' => $this->tenant->id, 'organization_id' => $organization->id, 'name' => 'Facility']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'facility_id' => $facility->id, 'name' => 'Main']);
        Role::findOrCreate('coach', 'web');
        Role::findOrCreate('event-organizer', 'web');
        Role::findOrCreate('player', 'web');
    }

    public function test_coach_sees_and_updates_only_assigned_sessions(): void
    {
        $coach = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $coach->assignRole('coach');
        $profile = CoachProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $coach->id, 'name' => $coach->name, 'hourly_rate' => 900]);
        $player = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $session = CoachingSession::create(['tenant_id' => $this->tenant->id, 'coach_profile_id' => $profile->id, 'user_id' => $player->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'amount' => 900, 'status' => 'scheduled']);
        Sanctum::actingAs($coach);

        $this->getJson('/api/v1/coach/workspace')->assertOk()->assertJsonPath('data.profile.id', $profile->id)->assertJsonPath('data.sessions.0.player.name', $player->name);
        $this->postJson('/api/v1/coach/sessions/'.$session->id.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_organizer_can_create_and_update_tournament(): void
    {
        $organizer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $organizer->assignRole('event-organizer');
        Sanctum::actingAs($organizer);

        $tournament = $this->postJson('/api/v1/organizer/tournaments', ['branch_id' => $this->branch->id, 'name' => 'Autumn Open', 'format' => 'singles', 'starts_at' => now()->addWeek()->toIso8601String(), 'capacity' => 32])->assertCreated();
        $id = $tournament->json('data.id');
        $this->patchJson('/api/v1/organizer/tournaments/'.$id, ['status' => 'open'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->getJson('/api/v1/organizer/tournaments/'.$id)->assertOk()->assertJsonPath('data.name', 'Autumn Open');
    }

    public function test_organizer_can_manage_registration_and_match_results(): void
    {
        $organizer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $organizer->assignRole('event-organizer');
        $player = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $player->assignRole('player');
        $court = Court::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'name' => 'Court A', 'base_price' => 500]);
        Sanctum::actingAs($organizer);

        $tournament = $this->postJson('/api/v1/organizer/tournaments', ['branch_id' => $this->branch->id, 'name' => 'Mobile Open', 'format' => 'singles', 'starts_at' => now()->addWeek()->toIso8601String()])->assertCreated();
        $tournamentId = $tournament->json('data.id');
        $registration = $this->postJson('/api/v1/organizer/tournaments/'.$tournamentId.'/registrations', ['user_id' => $player->id])->assertCreated();
        $registrationId = $registration->json('data.id');
        $match = $this->postJson('/api/v1/organizer/tournaments/'.$tournamentId.'/matches', ['court_id' => $court->id, 'player_one_registration_id' => $registrationId, 'round_number' => 1, 'match_number' => 1])->assertCreated();

        $this->patchJson('/api/v1/organizer/matches/'.$match->json('data.id'), ['status' => 'completed', 'winner_registration_id' => $registrationId, 'score' => '21-15'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->postJson('/api/v1/organizer/tournaments/'.$tournamentId.'/registrations/'.$registrationId.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    }
}

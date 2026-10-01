<?php

namespace Tests\Integration;

use App\Models\Booking;
use App\Models\CoachStudent;
use App\Models\Membership;
use App\Models\PlatformSubscriptionInvoice;
use App\Models\Rental;
use App\Models\SocialAccount;
use App\Models\TournamentMatch;
use App\Models\TournamentTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_command_creates_every_main_workflow_idempotently(): void
    {
        $this->artisan('court-hub:demo-data')->assertSuccessful();
        $this->artisan('court-hub:demo-data')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'demo.owner@court-hub.test']);
        $this->assertDatabaseHas('tenants', ['slug' => 'court-hub-sandbox', 'subscription_status' => 'trial']);
        $this->assertDatabaseHas('facilities', ['name' => 'Court Hub Central', 'registration_open' => true]);
        $this->assertSame(1, Booking::where('reference', 'DEMO-CONFIRMED')->count());
        $this->assertSame(2, Membership::count());
        $this->assertSame(2, Rental::count());
        $this->assertSame(1, TournamentMatch::count());
        $this->assertSame(1, TournamentTeam::count());
        $this->assertSame(1, CoachStudent::count());
        $this->assertSame(1, SocialAccount::where('provider', 'google')->count());
        $this->assertSame(2, PlatformSubscriptionInvoice::count());
        $this->assertTrue(User::where('email', 'demo.organizer@court-hub.test')->firstOrFail()->hasRole('event-organizer'));
    }
}

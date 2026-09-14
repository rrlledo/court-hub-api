<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class DatabaseApiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('Database API workflows require the PDO SQLite driver configured in phpunit.xml.');
        }

        parent::setUp();
        $response = $this->postJson('/api/v1/auth/register', [
            'tenant_name' => 'Integration Sports',
            'name' => 'Integration Owner',
            'email' => 'owner@example.test',
            'password' => 'IntegrationPassword123!',
            'password_confirmation' => 'IntegrationPassword123!',
        ])->assertCreated();
        $this->token = $response->json('data.token');
    }

    public function test_owner_can_create_the_core_facility_and_booking_workflow(): void
    {
        $organization = $this->api()->postJson('/api/v1/organizations', ['name' => 'Integration Organization'])->assertCreated();
        $facility = $this->api()->postJson('/api/v1/facilities', ['organization_id' => $organization->json('data.id'), 'name' => 'Integration Facility', 'timezone' => 'Asia/Manila'])->assertCreated();
        $branch = $this->api()->postJson('/api/v1/facilities/'.$facility->json('data.id').'/branches', ['name' => 'Integration Branch', 'timezone' => 'Asia/Manila'])->assertCreated();
        $court = $this->api()->postJson('/api/v1/branches/'.$branch->json('data.id').'/courts', ['name' => 'Court A', 'sport' => 'pickleball', 'base_price' => 500])->assertCreated();

        $booking = $this->api()->postJson('/api/v1/bookings', [
            'court_id' => $court->json('data.id'),
            'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'ends_at' => now()->addDay()->setTime(11, 0)->toIso8601String(),
        ])->assertCreated();

        $this->api()->postJson('/api/v1/bookings/'.$booking->json('data.id').'/confirm')->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->api()->getJson('/api/v1/bookings/'.$booking->json('data.id'))->assertOk()->assertJsonPath('data.id', $booking->json('data.id'));
    }

    public function test_owner_can_create_membership_payment_and_notification_records(): void
    {
        $plan = $this->api()->postJson('/api/v1/membership-plans', ['name' => 'Integration Plan', 'billing_period' => 'monthly', 'price' => 1000, 'duration_days' => 30])->assertCreated();
        $membership = $this->api()->postJson('/api/v1/memberships', ['membership_plan_id' => $plan->json('data.id')])->assertCreated();

        $this->api()->postJson('/api/v1/memberships/'.$membership->json('data.id').'/cards')->assertCreated();
        $this->api()->putJson('/api/v1/notification-preferences', ['email_enabled' => true, 'sms_enabled' => false, 'push_enabled' => true])->assertOk();
        $this->api()->postJson('/api/v1/notifications/test', ['title' => 'Integration test', 'message' => 'Queue this notification'])->assertStatus(202);
    }

    private function api(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }
}

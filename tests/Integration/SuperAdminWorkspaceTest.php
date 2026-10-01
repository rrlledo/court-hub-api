<?php

namespace Tests\Integration;

use App\Models\Facility;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperAdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_review_and_suspend_tenants(): void
    {
        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('court-owner', 'web');
        $primary = Tenant::create(['name' => 'Primary', 'slug' => 'primary']);
        $secondary = Tenant::create(['name' => 'Secondary', 'slug' => 'secondary', 'subscription_plan' => 'growth', 'subscription_status' => 'active', 'subscription_amount' => 2499]);
        Facility::create(['tenant_id' => $secondary->id, 'organization_id' => Organization::create(['tenant_id' => $secondary->id, 'name' => 'Secondary Org'])->id, 'name' => 'Secondary Facility']);
        $superAdmin = User::factory()->create(['tenant_id' => $primary->id]);
        $superAdmin->assignRole('super-admin');
        $owner = User::factory()->create(['tenant_id' => $secondary->id]);
        $owner->assignRole('court-owner');
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/super-admin/overview')->assertOk()->assertJsonPath('data.tenants', 2)->assertJsonPath('data.facilities', 1)->assertJsonPath('data.simulated_platform_mrr', 2499)->assertJsonPath('data.subscriptions_by_status.active', 1);
        $this->getJson('/api/v1/super-admin/tenants')->assertOk()->assertJsonFragment(['name' => 'Secondary', 'facilities_count' => 1]);
        $this->getJson('/api/v1/super-admin/tenants/'.$secondary->id)->assertOk()->assertJsonPath('data.tenant.facilities_count', 1)->assertJsonPath('data.users.0.id', $owner->id);
        $token = $owner->createToken('mobile')->accessToken;
        $this->postJson('/api/v1/super-admin/tenants/'.$secondary->id.'/users/'.$owner->id.'/revoke-sessions')->assertOk()->assertJsonPath('data.revoked', true);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
        $this->patchJson('/api/v1/super-admin/tenants/'.$secondary->id, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('tenants', ['id' => $secondary->id, 'is_active' => false]);
        $this->patchJson('/api/v1/super-admin/tenants/'.$secondary->id, ['subscription_plan' => 'enterprise', 'subscription_status' => 'past_due', 'subscription_amount' => 5999])->assertOk()->assertJsonPath('data.subscription_plan', 'enterprise')->assertJsonPath('data.subscription_status', 'past_due');
        $this->assertDatabaseHas('tenants', ['id' => $secondary->id, 'subscription_amount' => 5999]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/auth/me')->assertForbidden();
    }
}

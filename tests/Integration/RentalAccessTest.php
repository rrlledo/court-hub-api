<?php

namespace Tests\Integration;

use App\Models\Branch;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RentalAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $firstPlayer;

    private User $secondPlayer;

    private User $frontDesk;

    private Rental $firstRental;

    private Rental $secondRental;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['player', 'front-desk'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Rental Sports', 'slug' => 'rental-sports']);
        $organization = Organization::create(['tenant_id' => $this->tenant->id, 'name' => 'Rental Org']);
        $facility = Facility::create(['tenant_id' => $this->tenant->id, 'organization_id' => $organization->id, 'name' => 'Rental Facility']);
        $branch = Branch::create(['tenant_id' => $this->tenant->id, 'facility_id' => $facility->id, 'name' => 'Main']);
        $item = InventoryItem::create(['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'name' => 'Paddle', 'quantity_total' => 10, 'quantity_available' => 8, 'rental_price' => 100, 'deposit_amount' => 200]);

        $this->firstPlayer = $this->user('first@example.com', 'player');
        $this->secondPlayer = $this->user('second@example.com', 'player');
        $this->frontDesk = $this->user('desk@example.com', 'front-desk');
        $this->firstRental = Rental::create(['tenant_id' => $this->tenant->id, 'inventory_item_id' => $item->id, 'user_id' => $this->firstPlayer->id, 'quantity' => 1, 'rented_at' => now(), 'status' => 'active', 'amount' => 100, 'deposit_amount' => 200]);
        $this->secondRental = Rental::create(['tenant_id' => $this->tenant->id, 'inventory_item_id' => $item->id, 'user_id' => $this->secondPlayer->id, 'quantity' => 1, 'rented_at' => now(), 'status' => 'active', 'amount' => 100, 'deposit_amount' => 200]);
    }

    public function test_player_sees_only_their_own_rentals_and_cannot_open_another_players_rental(): void
    {
        Sanctum::actingAs($this->firstPlayer);

        $this->getJson('/api/v1/rentals')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->firstRental->id)
            ->assertJsonMissing(['id' => $this->secondRental->id]);

        $this->getJson('/api/v1/rentals/'.$this->secondRental->id)->assertForbidden();
    }

    public function test_front_desk_can_view_all_tenant_rentals(): void
    {
        Sanctum::actingAs($this->frontDesk);

        $this->getJson('/api/v1/rentals')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->firstRental->id])
            ->assertJsonFragment(['id' => $this->secondRental->id]);

        $this->getJson('/api/v1/rentals/'.$this->secondRental->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->secondRental->id);
    }

    public function test_front_desk_can_pick_a_tenant_player_for_a_rental(): void
    {
        Sanctum::actingAs($this->frontDesk);

        $this->getJson('/api/v1/rental-users')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->firstPlayer->id, 'name' => $this->firstPlayer->name])
            ->assertJsonFragment(['id' => $this->secondPlayer->id, 'name' => $this->secondPlayer->name]);

        Sanctum::actingAs($this->firstPlayer);
        $this->getJson('/api/v1/rental-users')->assertForbidden();
    }

    private function user(string $email, string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => $email]);
        $user->assignRole($role);

        return $user;
    }
}

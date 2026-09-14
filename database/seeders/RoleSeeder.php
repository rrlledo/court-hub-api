<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['super-admin', 'court-owner', 'facility-manager', 'front-desk', 'coach', 'event-organizer', 'player'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach (['users.view', 'users.manage', 'roles.view', 'activity-logs.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }
}

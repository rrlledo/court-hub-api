<?php

namespace App\Console\Commands;

use Database\Seeders\CourtHubDemoSeeder;
use Illuminate\Console\Command;

class SeedCourtHubDemoData extends Command
{
    protected $signature = 'court-hub:demo-data';

    protected $description = 'Create or refresh idempotent Court Hub demo users and workflow data';

    public function handle(CourtHubDemoSeeder $seeder): int
    {
        $seeder->run();
        $this->components->info('Court Hub demo data is ready.');
        $this->line('Password for all demo users: '.CourtHubDemoSeeder::PASSWORD);
        $this->table(['Role', 'Email'], [
            ['Super Admin', 'demo.superadmin@court-hub.test'],
            ['Court Owner', 'demo.owner@court-hub.test'],
            ['Facility Manager', 'demo.manager@court-hub.test'],
            ['Front Desk', 'demo.desk@court-hub.test'],
            ['Coach', 'demo.coach@court-hub.test'],
            ['Event Organizer', 'demo.organizer@court-hub.test'],
            ['Player', 'demo.player1@court-hub.test'],
            ['Player', 'demo.player2@court-hub.test'],
        ]);

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Membership;
use Illuminate\Console\Command;

class ExpireMemberships extends Command
{
    protected $signature = 'memberships:expire';

    protected $description = 'Expire active memberships whose end date has passed';

    public function handle(): int
    {
        $expired = Membership::query()
            ->where('status', 'active')
            ->whereDate('ends_on', '<', today())
            ->update(['status' => 'expired', 'auto_renew' => false]);

        $this->info("Expired {$expired} membership(s).");

        return self::SUCCESS;
    }
}

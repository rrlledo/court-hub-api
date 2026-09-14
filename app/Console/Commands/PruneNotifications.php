<?php

namespace App\Console\Commands;

use App\Models\UserNotification;
use Illuminate\Console\Command;

class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune {--days=90}';

    protected $description = 'Delete read notifications older than the retention period';

    public function handle(): int
    {
        $deleted = UserNotification::whereNotNull('read_at')->where('read_at', '<', now()->subDays((int) $this->option('days')))->delete();
        $this->info("Pruned {$deleted} notification(s).");

        return self::SUCCESS;
    }
}

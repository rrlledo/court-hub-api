<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

class ExpireUnpaidBookings extends Command
{
    protected $signature = 'bookings:expire';

    protected $description = 'Expire unpaid booking holds whose reservation window has elapsed';

    public function handle(): int
    {
        $expired = Booking::query()
            ->where('status', 'reserved')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $this->info("Expired {$expired} booking(s).");

        return self::SUCCESS;
    }
}

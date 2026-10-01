<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class VerifyPlayerEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function backoff(): array
    {
        return [10, 60, 300];
    }
}

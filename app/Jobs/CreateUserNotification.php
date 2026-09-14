<?php

namespace App\Jobs;

use App\Models\UserNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateUserNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $tenantId, public readonly int $userId, public readonly string $title, public readonly string $message, public readonly string $type = 'system', public readonly array $data = []) {}

    public function handle(): void
    {
        UserNotification::create(['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'channel' => 'in_app', 'type' => $this->type, 'title' => $this->title, 'message' => $this->message, 'data' => $this->data]);
    }
}

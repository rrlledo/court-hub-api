<?php

namespace App\Jobs;

use App\Models\PaymentWebhookEvent;
use App\Services\PayMongoCheckout;
use App\Services\SettleCheckout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCheckoutWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 60;

    public function __construct(public int $eventId) {}

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(PayMongoCheckout $provider, SettleCheckout $settler): void
    {
        $event = PaymentWebhookEvent::findOrFail($this->eventId);
        if ($event->processed_at) {
            return;
        }
        $session = $provider->retrieve($event->payload['checkout_id']);
        $settler->apply($session);
        $event->update(['processed_at' => now()]);
    }
}

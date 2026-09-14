<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PayMongoCheckout;
use App\Services\SettleCheckout;
use Illuminate\Console\Command;

class ReconcileCheckouts extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Recover missed checkout callbacks from authoritative provider status';

    public function handle(PayMongoCheckout $provider, SettleCheckout $settler): int
    {
        $failed = false;
        Payment::where('provider', 'paymongo')->whereIn('status', ['creating', 'pending'])
            ->whereNotNull('provider_reference')->chunkById(100, function ($payments) use ($provider, &$failed) {
                foreach ($payments as $payment) {
                    try {
                        $provider->reconcile($payment);
                    } catch (\Throwable $e) {
                        $failed = true;
                        $this->error('Could not reconcile payment '.$payment->id.'. Retry later.');
                    }
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

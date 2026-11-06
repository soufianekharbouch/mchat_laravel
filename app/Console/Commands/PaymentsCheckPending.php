<?php

namespace App\Console\Commands;

use App\Models\PaymentTransaction;
use App\Services\OnlinePaymentService;
use Illuminate\Console\Command;

/**
 * php artisan payments:check-pending
 *
 * Online payments still waiting: asked to Hesabe (customer closed the app
 * before coming back, webhook not received...). Paid -> packs paid.
 * Not paid after 2 hours -> expired (the packs stay "pending", the customer
 * can pay them again from the app).
 */
class PaymentsCheckPending extends Command
{
    protected $signature = 'payments:check-pending';

    protected $description = 'Check the Hesabe payments not confirmed yet';

    public function handle(OnlinePaymentService $payments): int
    {
        $checked = 0;
        $paid = 0;

        PaymentTransaction::query()
            ->where('status', PaymentTransaction::STATUS_INITIATED)
            ->where('created_at', '>=', now()->subMinutes(OnlinePaymentService::EXPIRE_AFTER_MINUTES))
            ->where('created_at', '<=', now()->subMinutes(3))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (PaymentTransaction $transaction) use ($payments, &$checked, &$paid) {
                $checked++;

                if ($payments->confirm($transaction)->isPaid()) {
                    $paid++;
                }
            });

        $expired = $payments->expireOld();

        $this->info("{$checked} checked, {$paid} paid, {$expired} expired.");

        return self::SUCCESS;
    }
}

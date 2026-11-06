<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\PaymentTransaction;
use App\Models\Purchase;
use App\Services\Hesabe\HesabeClient;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\ReminderPlanner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Online payment of the app packs (Hesabe: KNET / Visa / Mastercard).
 *
 *  start()   packs "pending" -> one payment_transactions row -> Hesabe page URL
 *  confirm() asks Hesabe the result (lookup by order reference):
 *              paid      -> every pack "paid" (deliveries can be planned, points)
 *              not found -> still waiting (refused / abandoned: expired later)
 *
 * The amount is always the sum of the packs in the database (never the app).
 * Paid twice is impossible (row locked, status checked).
 */
class OnlinePaymentService
{
    /** Hesabe refuses less than 0.200 KWD */
    public const MIN_AMOUNT = 0.2;

    /** An unpaid attempt is closed after this delay (packs stay pending, can be paid again) */
    public const EXPIRE_AFTER_MINUTES = 120;

    public function __construct(private HesabeClient $hesabe)
    {
    }

    /** Real payment ON: PAYMENT_GATEWAY=hesabe and the 4 keys set. */
    public static function enabled(): bool
    {
        return config('services.payment.gateway') === 'hesabe' && HesabeClient::configured();
    }

    /*
    |--------------------------------------------------------------------------
    | START
    |--------------------------------------------------------------------------
    */

    /**
     * @param Collection<int, Purchase> $purchases
     * @return array{transaction: PaymentTransaction, payment_url: ?string, paid: bool}
     */
    public function start(CustomerAccount $customer, Collection $purchases, string $method): array
    {
        $purchases = $purchases->map->fresh()->filter(fn (?Purchase $p) => $p && $p->status === 'pending')->values();

        if ($purchases->isEmpty()) {
            throw new RuntimeException('Nothing to pay.');
        }

        $amount = round((float) $purchases->sum(fn (Purchase $p) => (float) $p->total_amount), 3);

        $transaction = PaymentTransaction::create([
            'order_reference' => 'TMP-' . Str::uuid(),
            'gateway' => 'hesabe',
            'customer_account_id' => $customer->id,
            'purchase_ids' => $purchases->pluck('id')->values()->all(),
            'amount' => $amount,
            'currency' => $purchases->first()->currency ?: 'KWD',
            'payment_method' => $method,
            'status' => PaymentTransaction::STATUS_INITIATED,
        ]);

        // Our reference at Hesabe: MCP000123-AB12 (unique for every attempt)
        $transaction->order_reference = strtoupper((string) config('services.hesabe.reference_prefix', 'MCP'))
            . str_pad((string) $transaction->id, 6, '0', STR_PAD_LEFT)
            . '-' . strtoupper(Str::random(4));
        $transaction->save();

        // Everything paid with the loyalty points: nothing to pay online
        if ($amount <= 0) {
            $this->markPaid($transaction, ['status' => 'SUCCESSFUL', 'note' => 'Nothing to pay (loyalty points).'], true);

            return ['transaction' => $transaction->fresh(), 'payment_url' => null, 'paid' => true];
        }

        if ($amount < self::MIN_AMOUNT) {
            $this->fail($transaction, 'Amount below the Hesabe minimum (0.200 KWD).');

            throw new RuntimeException('min_amount');
        }

        try {
            $session = $this->hesabe->checkout(
                $amount,
                $transaction->order_reference,
                route('payments.hesabe.return', ['reference' => $transaction->order_reference]),
                route('payments.hesabe.failure', ['reference' => $transaction->order_reference]),
                route('payments.hesabe.webhook'),
                $this->paymentType($method),
                [
                    'variable1' => 'customer:' . $customer->id,
                    'variable2' => 'purchases:' . implode(',', $transaction->purchase_ids),
                ]
            );
        } catch (Throwable $exception) {
            $this->fail($transaction, Str::limit($exception->getMessage(), 250));

            throw $exception;
        }

        $transaction->update(['checkout_token' => Str::limit($session['token'], 250, '')]);

        foreach ($purchases as $purchase) {
            $purchase->forceFill(['payment_method' => $method])->save();
        }

        return ['transaction' => $transaction->fresh(), 'payment_url' => $session['payment_url'], 'paid' => false];
    }

    /** Hesabe paymentType: 0 = Hesabe page (customer chooses), 1 = KNET, 2 = card (MPGS)... */
    private function paymentType(string $method): int
    {
        return $method === 'knet'
            ? (int) config('services.hesabe.payment_type_knet', 0)
            : (int) config('services.hesabe.payment_type_card', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIRM (redirect, webhook, app, every 5 minutes)
    |--------------------------------------------------------------------------
    */

    /**
     * Asks Hesabe the result of this attempt. Returns the transaction updated.
     */
    public function confirm(PaymentTransaction $transaction): PaymentTransaction
    {
        if (!$transaction->isOpen() && !($transaction->status === PaymentTransaction::STATUS_FAILED && $transaction->created_at?->gt(now()->subDay()))) {
            return $transaction;
        }

        try {
            $record = $this->hesabe->transactionByReference($transaction->order_reference);
        } catch (Throwable $exception) {
            Log::warning('Hesabe lookup failed', ['reference' => $transaction->order_reference, 'error' => $exception->getMessage()]);
            $transaction->update(['checked_at' => now()]);

            return $transaction->fresh();
        }

        $transaction->forceFill([
            'checked_at' => now(),
            'gateway_response' => $record,
            'payment_token' => HesabeClient::field($record, ['token', 'paymentToken', 'payment_token']) ?? $transaction->payment_token,
            'payment_id' => HesabeClient::field($record, ['payment_id', 'paymentId', 'transaction_id', 'transactionId']) ?? $transaction->payment_id,
            'result_code' => HesabeClient::field($record, ['result_code', 'resultCode', 'status']) ?? $transaction->result_code,
        ])->save();

        if (!HesabeClient::isPaid($record)) {
            // Refused / abandoned: closed after the delay (the cron)
            if ($record && $transaction->isOpen()) {
                $this->fail($transaction, 'Payment refused or cancelled (' . ($transaction->result_code ?: 'no result') . ').');
            }

            return $transaction->fresh();
        }

        // Paid: the amount must be the one of the order
        $paidAmount = (float) (HesabeClient::field($record, ['amount', 'paid_amount']) ?? 0);

        if (abs($paidAmount - (float) $transaction->amount) > 0.0005) {
            Log::error('Hesabe amount mismatch', [
                'reference' => $transaction->order_reference,
                'expected' => (float) $transaction->amount,
                'paid' => $paidAmount,
            ]);

            $transaction->update(['failure_reason' => "Amount paid {$paidAmount} instead of {$transaction->amount}: check in Hesabe."]);

            return $transaction->fresh();
        }

        $this->markPaid($transaction, $record);

        return $transaction->fresh();
    }

    /** Unpaid attempts older than the delay: checked one last time, then expired. */
    public function expireOld(): int
    {
        $count = 0;

        PaymentTransaction::query()
            ->where('status', PaymentTransaction::STATUS_INITIATED)
            ->where('created_at', '<', now()->subMinutes(self::EXPIRE_AFTER_MINUTES))
            ->limit(50)
            ->get()
            ->each(function (PaymentTransaction $transaction) use (&$count) {
                $transaction = $this->confirm($transaction);

                if ($transaction->isOpen()) {
                    $transaction->update(['status' => PaymentTransaction::STATUS_EXPIRED, 'failure_reason' => 'Not paid within ' . self::EXPIRE_AFTER_MINUTES . ' minutes.']);
                    $count++;
                }
            });

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | PAID
    |--------------------------------------------------------------------------
    */

    private function markPaid(PaymentTransaction $transaction, array $record, bool $free = false): void
    {
        $paidPurchases = DB::transaction(function () use ($transaction, $record, $free) {
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->first();

            if (!$locked || $locked->isPaid()) {
                return collect();
            }

            $locked->forceFill([
                'status' => PaymentTransaction::STATUS_PAID,
                'paid_at' => now(),
                'failure_reason' => null,
            ])->save();

            $method = strtoupper($locked->payment_method ?: 'card');
            $reference = $locked->payment_id ?: $locked->payment_token ?: $locked->order_reference;
            $paid = collect();

            foreach ($locked->purchases() as $purchase) {
                $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->first();

                if (!$purchase || $purchase->status !== 'pending') {
                    if ($purchase && !in_array($purchase->status, ['paid', 'active'], true)) {
                        Log::error('Hesabe paid but purchase not pending', ['purchase_id' => $purchase->id, 'status' => $purchase->status, 'reference' => $locked->order_reference]);
                    }

                    continue;
                }

                $purchase->payment_reference = Str::limit($reference, 180, '');
                $purchase->save();

                $purchase->changeStatus(
                    'paid',
                    $free
                        ? 'Paid with the loyalty points (nothing to pay online).'
                        : "Paid online with Hesabe ({$method}). Ref {$locked->order_reference}"
                            . ($locked->payment_id ? ", payment id {$locked->payment_id}" : '')
                            . ', ' . number_format((float) $locked->amount, 3) . " {$locked->currency} for the order.",
                    'customer'
                );

                $paid->push($purchase);
            }

            // Paid, but the packs were already paid by another attempt: to refund
            if ($paid->isEmpty() && !$free) {
                $locked->forceFill(['failure_reason' => 'Paid, but the packs were already paid by another payment: REFUND it in Hesabe.'])->save();
                Log::error('Hesabe double payment', ['reference' => $locked->order_reference]);
            }

            return $paid;
        });

        // Deliveries planned automatically (day after the order, same
        // distribution as the previous similar pack), then the points
        if ($paidPurchases->isNotEmpty()) {
            try {
                app(AppPurchaseAutoPlanner::class)->planPaid($paidPurchases);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        // Notifications: "Payment confirmed" + reminders before the end of the packs
        if ($paidPurchases->isNotEmpty()) {
            try {
                app(NotificationCenter::class)->paymentConfirmed($transaction->fresh() ?? $transaction, $paidPurchases);

                $paidPurchases->each(fn (Purchase $purchase) => app(ReminderPlanner::class)->planPurchase($purchase->fresh() ?? $purchase));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        // Points earned by the packs (after the transaction)
        if ($paidPurchases->isNotEmpty() && class_exists(PetLoyaltyService::class)) {
            $paidPurchases->pluck('animal_id')->filter()->unique()->each(function ($animalId) {
                try {
                    app(PetLoyaltyService::class)->rebuildPetSafely((int) $animalId);
                } catch (Throwable) {
                    // recalculated tonight
                }
            });
        }
    }

    /** Hesabe sent the customer to the failure page: refused / cancelled now. */
    public function refusedByCustomerPage(PaymentTransaction $transaction): PaymentTransaction
    {
        $this->fail($transaction, 'Payment refused or cancelled on the Hesabe page.');

        return $transaction->fresh();
    }

    private function fail(PaymentTransaction $transaction, string $reason): void
    {
        if ($transaction->isOpen()) {
            $transaction->update(['status' => PaymentTransaction::STATUS_FAILED, 'failure_reason' => Str::limit($reason, 250, '')]);
        }
    }
}

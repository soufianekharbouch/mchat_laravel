<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Models\Purchase;
use App\Services\Hesabe\HesabeClient;
use App\Services\OnlinePaymentService;
use App\Support\CustomerSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Hesabe payment: pages of the customer + webhook + app.
 *
 *  GET  /payments/hesabe/return/{reference}    Hesabe page -> paid    (customer browser)
 *  GET  /payments/hesabe/failure/{reference}   Hesabe page -> refused / cancelled
 *  POST /api/payments/hesabe/webhook           Hesabe server -> our server
 *  POST /api/mobile/payments/{transaction}/status   app: result of the payment
 *  POST /api/mobile/payments/{transaction}/retry    app: pay again (packs still pending)
 *
 * The result is ALWAYS read at Hesabe (OnlinePaymentService::confirm),
 * never taken from the redirect / webhook data.
 */
class HesabePaymentController extends Controller
{
    public function __construct(private OnlinePaymentService $payments)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER BACK FROM THE HESABE PAGE -> back to the app
    |--------------------------------------------------------------------------
    */

    public function returned(Request $request, string $reference): View
    {
        return $this->backToApp($request, $reference, false);
    }

    public function failure(Request $request, string $reference): View
    {
        return $this->backToApp($request, $reference, true);
    }

    private function backToApp(Request $request, string $reference, bool $failurePage): View
    {
        $transaction = PaymentTransaction::where('order_reference', $reference)->first();

        if ($transaction) {
            $transaction = $this->payments->confirm($transaction);

            // Failure page and nothing paid at Hesabe: refused now (the app
            // shows it at once; a payment found later is still accepted)
            if ($failurePage && $transaction->isOpen()) {
                $transaction = $this->payments->refusedByCustomerPage($transaction);
            }
        } else {
            Log::warning('Hesabe return: unknown reference', ['reference' => $reference]);
        }

        $status = match (true) {
            !$transaction => 'failed',
            $transaction->isPaid() => 'paid',
            $transaction->isOpen() => 'pending',
            default => 'failed',
        };

        $scheme = (string) config('services.payment.app_scheme', 'mchat');
        $appUrl = $scheme . '://payment-result?' . http_build_query([
            'reference' => $reference,
            'transaction' => $transaction?->id,
            'status' => $status,
        ]);

        return view('payments.hesabe-return', [
            'status' => $status,
            'appUrl' => $appUrl,
            'amount' => $transaction ? number_format((float) $transaction->amount, 3) . ' ' . $transaction->currency : null,
            'reference' => $reference,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | WEBHOOK (Hesabe server, not signed: only a signal to check)
    |--------------------------------------------------------------------------
    */

    public function webhook(Request $request, HesabeClient $hesabe): JsonResponse
    {
        $body = $request->all();

        // Encrypted body ("data"): read it
        if (is_string($body['data'] ?? null) && ctype_xdigit($body['data'])) {
            $body = $hesabe->readRedirect($body['data']) ?? $body;
        }

        $reference = HesabeClient::field($body, ['orderReferenceNumber', 'order_reference_number', 'orderReference', 'order_reference']);
        $token = HesabeClient::field($body, ['token', 'paymentToken', 'payment_token']);

        $transaction = $reference ? PaymentTransaction::where('order_reference', $reference)->first() : null;

        if (!$transaction && $token) {
            $transaction = PaymentTransaction::where('payment_token', $token)->first();

            // Only the token: our reference read at Hesabe
            if (!$transaction) {
                try {
                    $record = $hesabe->transactionByToken($token);
                    $reference = HesabeClient::field($record, ['orderReferenceNumber', 'order_reference_number', 'orderReference', 'order_reference']);
                    $transaction = $reference ? PaymentTransaction::where('order_reference', $reference)->first() : null;
                } catch (Throwable $exception) {
                    Log::warning('Hesabe webhook: token lookup failed', ['error' => $exception->getMessage()]);
                }
            }
        }

        if (!$transaction) {
            Log::warning('Hesabe webhook: transaction not found', ['reference' => $reference, 'token' => $token]);

            return response()->json(['status' => true, 'message' => 'Unknown transaction']);
        }

        $transaction = $this->payments->confirm($transaction);

        return response()->json(['status' => true, 'payment' => $transaction->status]);
    }

    /*
    |--------------------------------------------------------------------------
    | APP
    |--------------------------------------------------------------------------
    */

    public function status(Request $request, PaymentTransaction $transaction): JsonResponse
    {
        $customer = CustomerSession::fromRequest($request);

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if ((int) $transaction->customer_account_id !== (int) $customer->id) {
            return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        }

        // Still open: asked to Hesabe now (customer just closed the payment page)
        if ($transaction->isOpen() || $transaction->status === PaymentTransaction::STATUS_FAILED) {
            $transaction = $this->payments->confirm($transaction);
        }

        return response()->json(['success' => true] + $this->format($transaction, $this->lang($request)));
    }

    public function retry(Request $request, PaymentTransaction $transaction): JsonResponse
    {
        $customer = CustomerSession::fromRequest($request);
        $lang = $this->lang($request);

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if ((int) $transaction->customer_account_id !== (int) $customer->id) {
            return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        }

        // Maybe paid in the meantime
        $transaction = $this->payments->confirm($transaction);

        if ($transaction->isPaid()) {
            return response()->json(['success' => true, 'already_paid' => true] + $this->format($transaction, $lang));
        }

        $pending = $transaction->purchases()->where('status', 'pending')->values();

        if ($pending->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => $lang === 'ar' ? 'لا يوجد ما يجب دفعه.' : 'Nothing left to pay.',
            ], 422);
        }

        try {
            $result = $this->payments->start($customer, $pending, $request->input('payment_method', $transaction->payment_method ?: 'knet'));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => $lang === 'ar' ? 'تعذر فتح صفحة الدفع. حاول مرة أخرى.' : 'Unable to open the payment page. Please try again.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'payment_url' => $result['payment_url'],
        ] + $this->format($result['transaction'], $lang));
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    private function format(PaymentTransaction $transaction, string $lang): array
    {
        return [
            'payment' => [
                'id' => $transaction->id,
                'reference' => $transaction->order_reference,
                'status' => $transaction->status,           // initiated | paid | failed | expired
                'paid' => $transaction->isPaid(),
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'method' => $transaction->payment_method,
                'payment_id' => $transaction->payment_id,
                'paid_at' => $transaction->paid_at?->toIso8601String(),
            ],
            'purchases' => $transaction->purchases()->map(fn (Purchase $p) => [
                'id' => $p->id,
                'reference' => $p->reference,
                'status' => $p->status,
                'product_name' => $p->localizedProductName($lang),
                'animal_id' => $p->animal_id,
                'animal_name' => $p->animal_name,
                'total_amount' => (float) $p->total_amount,
                // Planned automatically after the payment (null = to plan)
                'first_delivery' => \App\Services\AppPurchaseAutoPlanner::firstDelivery($p),
            ])->values(),
        ];
    }

    private function lang(Request $request): string
    {
        return $request->input('lang') === 'ar' ? 'ar' : 'en';
    }
}

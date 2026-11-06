<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\Purchase;
use App\Models\PurchaseDelivery;
use App\Support\CustomerSession;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The customer deletes his account himself (mobile app, required by
 * Apple / Google).
 *
 *  POST /api/mobile/account/delete   { access_token, confirm: "DELETE", reason? }
 *
 *  - sign in closed on every phone (token removed, devices detached)
 *  - personal data removed: phone, e-mail, name, password, settings
 *    (the same number can create a NEW account later)
 *  - packs not paid yet: cancelled
 *  - packs already paid with deliveries to come: delivered as planned
 *    (their delivery address is kept until then), the history of the
 *    orders is kept for the accounting
 *  - one line in account_deletions for the staff
 */
class MobileAccountDeletionController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        $customer = CustomerSession::fromRequest($request);
        $lang = $request->input('lang') === 'ar' ? 'ar' : 'en';

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // Second confirmation of the app
        if (strtoupper(trim((string) $request->input('confirm'))) !== 'DELETE') {
            return response()->json([
                'success' => false,
                'message' => $lang === 'ar' ? 'يرجى تأكيد حذف الحساب.' : 'Please confirm the deletion of your account.',
            ], 422);
        }

        try {
            DB::transaction(fn () => $this->deleteAccount($customer, Str::limit(trim((string) $request->input('reason', '')), 490, '')));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => $lang === 'ar' ? 'تعذر حذف الحساب. حاول مرة أخرى أو تواصل معنا.' : 'Unable to delete your account. Please try again or contact us.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => $lang === 'ar' ? 'تم حذف حسابك.' : 'Your account has been deleted.',
        ]);
    }

    private function deleteAccount(CustomerAccount $customer, string $reason): void
    {
        /** @var CustomerAccount $customer */
        $customer = CustomerAccount::whereKey($customer->id)->lockForUpdate()->firstOrFail();
        $subscription = $customer->subscription;

        $purchases = Purchase::query()
            ->where('customer_account_id', $customer->id)
            ->when($customer->subscription_id, fn ($q) => $q->orWhere('subscription_id', $customer->subscription_id))
            ->get();

        /*
         * 1. Packs not paid: cancelled.
         */
        $cancelled = 0;

        foreach ($purchases->where('status', 'pending') as $purchase) {
            try {
                $purchase->changeStatus('cancelled', 'Account deleted by the customer in the app.', 'customer');
                $cancelled++;
            } catch (Throwable $exception) {
                Log::warning('Account deletion: pack not cancelled', ['purchase_id' => $purchase->id, 'error' => $exception->getMessage()]);
            }
        }

        /*
         * 2. Paid deliveries still to come: delivered as planned.
         */
        $futureDeliveries = PurchaseDelivery::query()
            ->whereIn('purchase_id', $purchases->whereIn('status', ['paid', 'active'])->pluck('id'))
            ->whereIn('status', ['scheduled', 'prepared'])
            ->whereDate('delivery_date', '>=', Carbon::today())
            ->get(['id', 'customer_address_id']);

        $keptAddressIds = $futureDeliveries->pluck('customer_address_id')->filter()->unique()->all();

        /*
         * 3. Addresses: removed, except the ones of the deliveries to come.
         */
        $customer->addresses()->whereNotIn('id', $keptAddressIds ?: [0])->delete();

        /*
         * 4. Phones of the customer: no more notifications.
         */
        if (Schema::hasTable('mobile_devices') && Schema::hasColumn('mobile_devices', 'customer_account_id')) {
            DB::table('mobile_devices')->where('customer_account_id', $customer->id)->update(['customer_account_id' => null]);
        }

        /*
         * 5. Subscription: personal data removed (orders kept).
         */
        if ($subscription) {
            $subscription->forceFill([
                'subscriber_first_name' => 'Deleted customer',
                'subscriber_last_name' => null,
                'subscriber_phone' => 'deleted-' . $customer->id,
                'subscriber_address' => $keptAddressIds ? $subscription->subscriber_address : '',
                'subscriber_note' => trim(($subscription->subscriber_note ? $subscription->subscriber_note . "\n" : '')
                    . 'Account deleted by the customer in the app on ' . now()->format('d/m/Y H:i') . '.'),
            ])->save();
        }

        /*
         * 6. App account: anonymous, signed out everywhere.
         *    (phone NOT NULL + unique: "deleted-<id>")
         */
        $customer->forceFill([
            'phone' => 'deleted-' . $customer->id,
            'email' => null,
            'password' => Str::random(48),
            'settings' => null,
            'initial_password_encrypted' => null,
            'access_token_hash' => null,
            'access_token_expires_at' => null,
            'remember_token' => null,
        ]);

        if (Schema::hasColumn('customer_accounts', 'deleted_at')) {
            $customer->forceFill(['deleted_at' => now()]);
        }

        $customer->save();

        /*
         * 7. Trace for the staff.
         */
        if (Schema::hasTable('account_deletions')) {
            DB::table('account_deletions')->insert([
                'customer_account_id' => $customer->id,
                'subscription_id' => $subscription?->id,
                'subscription_code' => $subscription?->code,
                'reason' => $reason !== '' ? $reason : null,
                'pending_cancelled' => $cancelled,
                'future_deliveries' => $futureDeliveries->count(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Log::info('Customer account deleted from the app', [
            'customer_account_id' => $customer->id,
            'subscription_id' => $subscription?->id,
            'pending_cancelled' => $cancelled,
            'future_deliveries' => $futureDeliveries->count(),
        ]);
    }
}

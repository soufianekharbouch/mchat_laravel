<?php

namespace App\Services\Notifications;

use App\Models\CustomerAccount;
use App\Models\MobileNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers who receive a notification. Only customers with an app
 * account (connected customers), never deleted accounts.
 */
class NotificationAudience
{
    private static ?bool $hasDeletedAt = null;

    /** Accounts that can receive notifications. */
    public static function customers(): Builder
    {
        self::$hasDeletedAt ??= Schema::hasColumn('customer_accounts', 'deleted_at');

        return CustomerAccount::query()
            ->when(self::$hasDeletedAt, fn ($query) => $query->whereNull('customer_accounts.deleted_at'));
    }

    /** Has a paid / active pack, or deliveries still to come. */
    public static function withActivePlan(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query->where(function ($query) use ($today) {
            $query->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('purchases')
                    ->whereColumn('purchases.customer_account_id', 'customer_accounts.id')
                    ->whereIn('purchases.status', ['paid', 'active']);
            })->orWhereExists(function ($sub) use ($today) {
                $sub->select(DB::raw(1))
                    ->from('planned_orders')
                    ->whereColumn('planned_orders.subscription_id', 'customer_accounts.subscription_id')
                    ->where('planned_orders.status', 'planned')
                    ->whereDate('planned_orders.scheduled_for', '>=', $today);
            });
        });
    }

    /**
     * Ids of the customers of a target.
     *
     * @param int[] $selected for selected_customers
     * @return int[]
     */
    public static function ids(string $target, array $selected = []): array
    {
        $query = self::customers();

        $query = match ($target) {
            'selected_customers' => $query->whereIn('customer_accounts.id', array_map('intval', $selected)),
            'active_plan' => self::withActivePlan($query),
            'no_active_plan' => $query->whereNotIn('customer_accounts.id', self::withActivePlan(self::customers())->select('customer_accounts.id')),
            'all_customers' => $query,
            default => $query->whereRaw('1 = 0'),
        };

        return $query->pluck('customer_accounts.id')->map(fn ($id) => (int) $id)->all();
    }

    public static function count(string $target, array $selected = []): int
    {
        return count(self::ids($target, $selected));
    }

    /**
     * Writes the recipients of a notification: adds the missing customers,
     * removes the ones no longer in the target (only not sent yet).
     *
     * @param int[] $customerIds
     */
    public static function syncRecipients(MobileNotification $notification, array $customerIds): void
    {
        $customerIds = array_values(array_unique(array_map('intval', $customerIds)));

        DB::table('mobile_notification_recipients')
            ->where('notification_id', $notification->id)
            ->where('push_status', 'pending')
            ->where(fn ($query) => $query->whereNull('customer_account_id')
                ->orWhereNotIn('customer_account_id', $customerIds ?: [0]))
            ->delete();

        $existing = DB::table('mobile_notification_recipients')
            ->where('notification_id', $notification->id)
            ->pluck('customer_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = array_values(array_diff($customerIds, $existing));

        if ($missing === []) {
            return;
        }

        // Latest active phone of each customer (shown in the back-office)
        $devices = DB::table('mobile_devices')
            ->whereIn('customer_account_id', $missing)
            ->where('is_active', 1)
            ->orderBy('last_seen_at')
            ->pluck('id', 'customer_account_id')
            ->all();

        $now = now();

        foreach (array_chunk($missing, 500) as $chunk) {
            DB::table('mobile_notification_recipients')->insert(array_map(fn ($customerId) => [
                'notification_id' => $notification->id,
                'customer_account_id' => $customerId,
                'mobile_device_id' => $devices[$customerId] ?? null,
                'push_status' => 'pending',
                'push_sent_at' => null,
                'viewed_at' => null,
                'dismissed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }

    /** "Name Surname · 5000 0000" */
    public static function label(?CustomerAccount $customer): string
    {
        if (!$customer) {
            return '—';
        }

        $customer->loadMissing('subscription');

        $name = trim(($customer->subscription?->subscriber_first_name ?? '') . ' ' . ($customer->subscription?->subscriber_last_name ?? ''));
        $phone = $customer->phone ?: $customer->subscription?->subscriber_phone;

        return trim(($name !== '' ? $name : 'Customer #' . $customer->id) . ($phone ? ' · ' . $phone : ''));
    }
}

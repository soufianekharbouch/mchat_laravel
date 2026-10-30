<?php

namespace App\Support;

use App\Models\PlannedOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One delivery = same customer (subscription) + same day + same delivery
 * slot + same address. Several pets in it = ONE order sent to JAAK.
 *
 * Slot:    pack delivery slot (app) > its address slot > default address
 *          slot (customer_addresses.delivery_slot) > subscriber_delivery_slot
 * Address: pack delivery address > default address > subscriber_address
 */
class DeliveryGroup
{
    /** Adds the joins needed by keySql() / slotSql() (once per query). */
    public static function join(Builder $query): Builder
    {
        $packDeliveries = DB::table('purchase_deliveries')
            ->selectRaw('planned_order_id, MAX(delivery_slot) AS slot, MAX(customer_address_id) AS address_id')
            ->whereNotNull('planned_order_id')
            ->where('status', '!=', 'cancelled')
            ->groupBy('planned_order_id');

        $defaultAddresses = DB::table('customer_addresses')
            ->join('customer_accounts', 'customer_accounts.id', '=', 'customer_addresses.customer_account_id')
            ->where('customer_addresses.is_default', true)
            ->selectRaw('customer_accounts.subscription_id, MAX(customer_addresses.delivery_slot) AS slot, MAX(customer_addresses.address) AS address')
            ->groupBy('customer_accounts.subscription_id');

        return $query
            ->leftJoinSub($packDeliveries, 'dg_pd', 'dg_pd.planned_order_id', '=', 'planned_orders.id')
            ->leftJoin('customer_addresses as dg_ca', 'dg_ca.id', '=', 'dg_pd.address_id')
            ->leftJoin('subscriptions as dg_s', 'dg_s.id', '=', 'planned_orders.subscription_id')
            // Default address of the customer (customer_addresses.is_default)
            ->leftJoinSub($defaultAddresses, 'dg_da', 'dg_da.subscription_id', '=', 'planned_orders.subscription_id');
    }

    public static function slotSql(): string
    {
        return "COALESCE(NULLIF(dg_pd.slot, ''), NULLIF(dg_ca.delivery_slot, ''), NULLIF(dg_da.slot, ''), NULLIF(dg_s.subscriber_delivery_slot, ''), '')";
    }

    public static function addressSql(): string
    {
        return "LOWER(TRIM(COALESCE(NULLIF(dg_ca.address, ''), NULLIF(dg_da.address, ''), dg_s.subscriber_address, '')))";
    }

    /** md5(subscription | day | slot | address) */
    public static function keySql(): string
    {
        return "MD5(CONCAT_WS('|', planned_orders.subscription_id, planned_orders.scheduled_for, "
            . self::slotSql() . ', ' . self::addressSql() . '))';
    }

    /** Lowest status of the group: 0 overdue, 1 planned, 2 prepared, 3 shipped, 4 delivered */
    public static function rankSql(): string
    {
        return "CASE planned_orders.status WHEN 'overdue' THEN 0 WHEN 'planned' THEN 1 WHEN 'prepared' THEN 2 "
            . "WHEN 'shipped' THEN 3 WHEN 'delivered' THEN 4 ELSE 1 END";
    }

    public const RANKS = ['overdue', 'planned', 'prepared', 'shipped', 'delivered'];

    /** Orders with their delivery_key / delivery_slot_resolved columns. */
    public static function ordersQuery(): Builder
    {
        return self::join(PlannedOrder::query())
            ->select('planned_orders.*')
            ->selectRaw(self::keySql() . ' AS delivery_key')
            ->selectRaw(self::slotSql() . ' AS delivery_slot_resolved');
    }

    /**
     * All the orders delivered together with $order (itself included).
     *
     * @return Collection<int, PlannedOrder>
     */
    public static function siblings(PlannedOrder $order): Collection
    {
        $orders = self::ordersQuery()
            ->where('planned_orders.subscription_id', $order->subscription_id)
            ->whereDate('planned_orders.scheduled_for', $order->scheduled_for)
            ->orderBy('planned_orders.id')
            ->get();

        $key = $orders->firstWhere('id', $order->id)?->delivery_key;

        return $orders->where('delivery_key', $key)->values();
    }

    /*
    |--------------------------------------------------------------------------
    | ORDER NUMBER OF A DELIVERY: DL-261021-1234 (-2, -3...)
    |--------------------------------------------------------------------------
    |
    | DL - day (yymmdd) - subscription id. Same customer, same day but another
    | slot or address = second delivery: -2. Stable, searchable, also sent
    | to JAAK (external_ref).
    |
    */

    public const NUMBER_PREFIX = 'DL';

    public static function number(int $subscriptionId, $date, int $index = 1): string
    {
        return self::NUMBER_PREFIX . '-' . \Carbon\Carbon::parse($date)->format('ymd') . '-' . $subscriptionId
            . ($index > 1 ? '-' . $index : '');
    }

    /** "DL-261021-1234", "261021-1234-2"... -> [date, subscription_id, index] */
    public static function parseNumber(string $value): ?array
    {
        if (!preg_match('/^(?:DL-?)?(\d{6})-(\d+)(?:-(\d+))?$/i', trim($value, " #\t"), $m)) {
            return null;
        }

        try {
            $date = \Carbon\Carbon::createFromFormat('ymd', $m[1])->toDateString();
        } catch (\Throwable) {
            return null;
        }

        return ['date' => $date, 'subscription_id' => (int) $m[2], 'index' => (int) ($m[3] ?? 1)];
    }

    /**
     * Numbers of the deliveries of these orders: [delivery_key => "DL-..."].
     * Index = order of the deliveries of the customer that day (first order id).
     *
     * @param iterable<PlannedOrder> $orders orders having delivery_key
     */
    public static function numbers(iterable $orders): array
    {
        $orders = collect($orders);

        if ($orders->isEmpty()) {
            return [];
        }

        $all = self::ordersQuery()
            ->whereIn('planned_orders.subscription_id', $orders->pluck('subscription_id')->unique()->values())
            ->whereIn('planned_orders.scheduled_for', $orders->map(fn ($o) => $o->scheduled_for?->toDateString())->unique()->values())
            ->orderBy('planned_orders.id')
            ->get();

        $numbers = [];

        $all->groupBy(fn ($o) => $o->subscription_id . '|' . $o->scheduled_for?->toDateString())
            ->each(function ($dayOrders) use (&$numbers) {
                $index = 0;

                // ordered by id: the first key met = delivery 1
                foreach ($dayOrders->pluck('delivery_key')->unique() as $key) {
                    $first = $dayOrders->firstWhere('delivery_key', $key);
                    $numbers[$key] = self::number($first->subscription_id, $first->scheduled_for, ++$index);
                }
            });

        return $numbers;
    }

    public static function numberFor(PlannedOrder $order): string
    {
        $key = self::siblings($order)->first()?->delivery_key;

        return self::numbers([$order])[$key]
            ?? self::number((int) $order->subscription_id, $order->scheduled_for);
    }
}

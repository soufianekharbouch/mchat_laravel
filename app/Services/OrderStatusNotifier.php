<?php

namespace App\Services;

use App\Models\PlannedOrder;
use App\Services\Notifications\NotificationCenter;
use App\Support\DeliveryGroup;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notification to the customer when his delivery changes status.
 * One per delivery (all its pets) and per step:
 *
 *   prepared    all the pets of the delivery are prepared (daily report)
 *   on_the_way  the JAAK driver has the parcel (DROPOFF)
 *   delivered   delivered (JAAK DELIVERED)
 *
 * Same calls as before (DailyReportController, JaakDispatcher): the
 * notification now goes through the Notifications menu
 * (NotificationCenter): texts, on/off and destination set there.
 * Never blocks the caller.
 */
class OrderStatusNotifier
{
    public const STEPS = ['prepared', 'on_the_way', 'delivered'];

    public function __construct(private NotificationCenter $center)
    {
    }

    /**
     * @param Collection<int, PlannedOrder> $orders orders of ONE delivery
     */
    public function notify(Collection $orders, string $step): void
    {
        try {
            if (!config('services.order_notifications.enabled', true) || !in_array($step, self::STEPS, true) || $orders->isEmpty()) {
                return;
            }

            // Old orders (before the start of the notifications) are never notified
            $first = $orders->first();
            $start = config('services.order_notifications.start_date') ?: config('services.jaak.start_date');

            if ($start && $first->scheduled_for && $first->scheduled_for->toDateString() < Carbon::parse($start)->toDateString()) {
                return;
            }

            $this->center->orderStatus($orders, $step);
        } catch (Throwable $exception) {
            Log::warning('Order status notification failed', [
                'step' => $step,
                'planned_order_ids' => $orders->pluck('id')->all(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** Daily report: notified when the LAST pet of the delivery is prepared. */
    public function notifyIfDeliveryPrepared(PlannedOrder $order): void
    {
        try {
            $siblings = DeliveryGroup::siblings($order);

            $allPrepared = $siblings->isNotEmpty() && $siblings->every(
                fn ($o) => in_array($o->status, ['prepared', 'shipped', 'delivered'], true)
            );

            if ($allPrepared) {
                $this->notify($siblings, 'prepared');
            }
        } catch (Throwable $exception) {
            Log::warning('Order prepared notification failed', ['planned_order_id' => $order->id, 'error' => $exception->getMessage()]);
        }
    }
}

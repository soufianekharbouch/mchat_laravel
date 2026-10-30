<?php

namespace App\Services;

use App\Http\Controllers\MobileNotificationController;
use App\Models\CustomerAccount;
use App\Models\MobileNotification;
use App\Models\PlannedOrder;
use App\Support\DeliveryGroup;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push notification to the customer (mobile app) when his delivery
 * changes status. One per delivery (all its pets) and per step:
 *
 *   prepared    all the pets of the delivery are prepared (daily report)
 *   on_the_way  the JAAK driver has the parcel (DROPOFF)
 *   delivered   delivered (JAAK DELIVERED)
 *
 * Arabic or English: the language of each device (as the other
 * notifications of the app). Never blocks the caller.
 */
class OrderStatusNotifier
{
    public const STEPS = ['prepared', 'on_the_way', 'delivered'];

    /**
     * @param Collection<int, PlannedOrder> $orders orders of ONE delivery
     */
    public function notify(Collection $orders, string $step): void
    {
        try {
            $this->send($orders, $step);
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

    private function send(Collection $orders, string $step): void
    {
        if (!config('services.order_notifications.enabled', true) || !in_array($step, self::STEPS, true) || $orders->isEmpty()) {
            return;
        }

        /** @var PlannedOrder $first */
        $first = $orders->first();
        $first->loadMissing('animal');

        // Old orders (before the start of the notifications) are never notified
        $start = config('services.order_notifications.start_date') ?: config('services.jaak.start_date');

        if ($start && $first->scheduled_for && $first->scheduled_for->toDateString() < Carbon::parse($start)->toDateString()) {
            return;
        }

        $account = CustomerAccount::where('subscription_id', $first->subscription_id)->first();

        if (!$account) {
            return; // customer without the app
        }

        $key = DeliveryGroup::siblings($first)->first()?->delivery_key ?: md5('po-' . $first->id);

        // Once per delivery and per step (unique key)
        $inserted = DB::table('order_status_notifications')->insertOrIgnore([
            'delivery_key' => $key,
            'step' => $step,
            'subscription_id' => $first->subscription_id,
            'customer_account_id' => $account->id,
            'delivery_date' => $first->scheduled_for?->toDateString(),
            'planned_order_ids' => mb_substr($orders->pluck('id')->implode(','), 0, 250),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (!$inserted) {
            return; // already notified
        }

        $texts = $this->texts($orders, $step);

        $notification = DB::transaction(function () use ($texts, $account) {
            $notification = MobileNotification::create([
                'type' => MobileNotification::TYPE_DELIVERY,
                'title_en' => $texts['title_en'],
                'title_ar' => $texts['title_ar'],
                'body_en' => $texts['body_en'],
                'body_ar' => $texts['body_ar'],
                'target_type' => 'selected_customers',
                'status' => 'draft',
            ]);

            DB::table('mobile_notification_recipients')->insert([
                'notification_id' => $notification->id,
                'customer_account_id' => $account->id,
                'mobile_device_id' => null,
                'push_status' => 'pending',
                'push_sent_at' => null,
                'viewed_at' => null,
                'dismissed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $notification;
        });

        DB::table('order_status_notifications')
            ->where('delivery_key', $key)
            ->where('step', $step)
            ->update(['mobile_notification_id' => $notification->id, 'updated_at' => now()]);

        // Push now (same sending as the back-office notifications)
        $response = app(MobileNotificationController::class)->send(
            Request::create('/internal/order-status-notification', 'POST'),
            $notification->id
        );

        $result = $response->getData(true);

        if ($response->getStatusCode() >= 400 || !($result['success'] ?? false)) {
            Log::warning('Order status push not delivered', ['notification_id' => $notification->id, 'response' => $result]);
        }
    }

    /**
     * Titles and messages (English + Arabic).
     *
     * @return array{title_en: string, title_ar: string, body_en: string, body_ar: string}
     */
    public function texts(Collection $orders, string $step): array
    {
        $orders->each(fn ($o) => $o->loadMissing('animal'));

        $names = $orders->map(fn ($o) => trim((string) $o->animal?->name))->filter()->unique()->values();
        $petsEn = $names->isEmpty() ? 'your pet' : $names->implode(' & ');
        $petsAr = $names->isEmpty() ? 'حيوانك الأليف' : $names->implode(' و ');

        $date = $orders->first()?->scheduled_for;
        $dateText = $date ? $date->format('d/m') : '';

        return match ($step) {
            'prepared' => [
                'title_en' => 'Your order is ready 🐾',
                'title_ar' => 'طلبك جاهز 🐾',
                'body_en' => "{$petsEn}'s meals" . ($dateText ? " for {$dateText}" : '') . ' are prepared and will be handed to the driver soon.',
                'body_ar' => "وجبات {$petsAr}" . ($dateText ? " ليوم {$dateText}" : '') . ' جاهزة وسيتم تسليمها للسائق قريباً.',
            ],
            'on_the_way' => [
                'title_en' => 'Your order is on the way 🚚',
                'title_ar' => 'طلبك في الطريق 🚚',
                'body_en' => "The driver has {$petsEn}'s meals and is heading to you.",
                'body_ar' => "السائق استلم وجبات {$petsAr} وهو في الطريق إليك.",
            ],
            default => [
                'title_en' => 'Order delivered ✅',
                'title_ar' => 'تم توصيل طلبك ✅',
                'body_en' => "{$petsEn}'s meals have been delivered. Enjoy!",
                'body_ar' => "تم توصيل وجبات {$petsAr}. بالهناء والشفاء!",
            ],
        };
    }
}

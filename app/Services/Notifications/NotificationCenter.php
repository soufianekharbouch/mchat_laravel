<?php

namespace App\Services\Notifications;

use App\Models\CustomerAccount;
use App\Models\MobileNotification;
use App\Models\PaymentTransaction;
use App\Models\PlannedOrder;
use App\Models\Purchase;
use App\Support\AppNotifications\NotificationTypes;
use App\Support\DeliveryGroup;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * ALL the notifications of the app go through here:
 *
 *   auto()      automatic notification for one customer (once per dedupe key)
 *   send()      sends one notification now (push + list of the app)
 *   dispatchDue() every minute: the scheduled ones whose date is reached
 *
 * Every notification is first written "scheduled" with its date, so the
 * team sees it in Notifications and can change / cancel it before.
 * Events (order prepared...) are scheduled "now" and sent at once.
 *
 * Never throws to the caller (an order or a payment is never blocked).
 */
class NotificationCenter
{
    /** A sending interrupted (server stopped) is closed after this delay */
    private const SENDING_TIMEOUT_MINUTES = 15;

    public function __construct(private PushSender $push)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | EVENTS
    |--------------------------------------------------------------------------
    */

    /**
     * Delivery status (one delivery = all its pets).
     *
     * @param Collection<int, PlannedOrder> $orders
     * @param string $step prepared / on_the_way / delivered
     */
    public function orderStatus(Collection $orders, string $step): ?MobileNotification
    {
        $type = [
            'prepared' => 'order_prepared',
            'on_the_way' => 'order_on_the_way',
            'delivered' => 'order_delivered',
        ][$step] ?? null;

        if (!$type || $orders->isEmpty()) {
            return null;
        }

        return $this->safely(function () use ($orders, $type) {
            /** @var PlannedOrder $first */
            $first = $orders->first();
            $orders->each(fn ($order) => $order->loadMissing('animal'));

            $customer = NotificationAudience::customers()->where('subscription_id', $first->subscription_id)->first();

            if (!$customer) {
                return null; // customer without the app
            }

            $key = DeliveryGroup::siblings($first)->first()?->delivery_key ?: md5('po-' . $first->id);

            return $this->auto($type, $customer->id, [
                'pet' => $this->petNames($orders->map(fn ($order) => $order->animal?->name)),
                'date' => $first->scheduled_for?->format('d/m') ?? '',
            ], [
                'planned_order_id' => $first->id,
                'animal_id' => $first->animal_id,
                'date' => $first->scheduled_for?->toDateString(),
            ], null, "{$type}:{$key}", [
                'context' => ['planned_order_ids' => $orders->pluck('id')->values()->all()],
            ]);
        }, ['type' => $type, 'planned_order_ids' => $orders->pluck('id')->all()]);
    }

    /** PREMIUM pack: custom recipes assigned by the team. */
    public function premiumReady(Purchase $purchase): ?MobileNotification
    {
        if (!$purchase->customer_account_id) {
            return null;
        }

        return $this->safely(function () use ($purchase) {
            $purchase->loadMissing('animal');

            return $this->auto('premium_ready', (int) $purchase->customer_account_id, [
                'pet' => $this->petNames(collect([$purchase->animal?->name ?? $purchase->animal_name])),
                'product' => $this->productName($purchase),
            ], [
                'purchase_id' => $purchase->id,
                'animal_id' => $purchase->animal_id,
                'product_id' => $purchase->product_id,
            ], null, 'premium_ready:' . $purchase->id, ['purchase_id' => $purchase->id]);
        }, ['type' => 'premium_ready', 'purchase_id' => $purchase->id]);
    }

    /**
     * Online payment confirmed.
     *
     * @param Collection<int, Purchase> $purchases packs paid by it
     */
    public function paymentConfirmed(PaymentTransaction $transaction, Collection $purchases): ?MobileNotification
    {
        if (!$transaction->customer_account_id || $purchases->isEmpty()) {
            return null;
        }

        return $this->safely(function () use ($transaction, $purchases) {
            $purchases->each(fn ($purchase) => $purchase->loadMissing('animal'));
            $first = $purchases->first();

            return $this->auto('payment_confirmed', (int) $transaction->customer_account_id, [
                'pet' => $this->petNames($purchases->map(fn ($purchase) => $purchase->animal?->name ?? $purchase->animal_name)),
                'amount' => number_format((float) $transaction->amount, 3),
                'reference' => (string) $transaction->order_reference,
            ], [
                'purchase_id' => $first->id,
                'animal_id' => $first->animal_id,
                'product_id' => $first->product_id,
            ], null, 'payment_confirmed:' . $transaction->id, [
                'purchase_id' => $first->id,
                'context' => ['purchase_ids' => $purchases->pluck('id')->values()->all(), 'transaction_id' => $transaction->id],
            ]);
        }, ['type' => 'payment_confirmed', 'transaction_id' => $transaction->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | AUTOMATIC NOTIFICATION
    |--------------------------------------------------------------------------
    */

    /**
     * Writes an automatic notification for one customer, with the texts
     * of its type. Sent now when its date is reached.
     *
     * Returns null when: unknown / disabled type, customer without app,
     * or already created (same dedupe key, even if cancelled).
     *
     * @param array $vars    {placeholders} of the texts
     * @param array $params  given to the app with the destination
     * @param array $extra   purchase_id, context
     */
    public function auto(
        string $type,
        int $customerId,
        array $vars,
        array $params = [],
        ?Carbon $at = null,
        ?string $dedupeKey = null,
        array $extra = []
    ): ?MobileNotification {
        $definition = NotificationTypes::get($type);

        if (!$definition || !NotificationTypes::enabled($type)) {
            return null;
        }

        if (!NotificationAudience::customers()->whereKey($customerId)->exists()) {
            return null;
        }

        if ($dedupeKey && MobileNotification::where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $settings = NotificationTypes::settings($type);
        $at ??= now()->addMinutes(max(0, (int) $settings->delay_minutes));

        try {
            $notification = DB::transaction(function () use ($type, $definition, $settings, $customerId, $vars, $params, $at, $dedupeKey, $extra) {
                $notification = MobileNotification::create([
                    'type' => $definition['category'] ?? MobileNotification::TYPE_GENERAL,
                    'source' => MobileNotification::SOURCE_AUTO,
                    'auto_type' => $type,
                    'target_type' => 'selected_customers',
                    'status' => 'scheduled',
                    'scheduled_at' => $at,
                    'title_en' => NotificationTypes::render($settings->title_en, $vars, 'en'),
                    'title_ar' => NotificationTypes::render($settings->title_ar, $vars, 'ar'),
                    'body_en' => NotificationTypes::render($settings->body_en, $vars, 'en'),
                    'body_ar' => NotificationTypes::render($settings->body_ar, $vars, 'ar'),
                    'destination' => NotificationTypes::destination($type),
                    'destination_params' => array_filter($params, fn ($value) => $value !== null && $value !== ''),
                    'dedupe_key' => $dedupeKey,
                    'customer_account_id' => $customerId,
                    'purchase_id' => $extra['purchase_id'] ?? null,
                    'context' => array_merge(['vars' => $vars], $extra['context'] ?? []),
                ]);

                NotificationAudience::syncRecipients($notification, [$customerId]);

                return $notification;
            });
        } catch (QueryException $exception) {
            // Created at the same moment by another process (unique dedupe key)
            if ((string) $exception->getCode() === '23000') {
                return null;
            }

            throw $exception;
        }

        if ($notification->scheduled_at->lte(now())) {
            $this->send($notification);
        }

        return $notification;
    }

    /**
     * Texts of an automatic notification written again with the current
     * texts of its type (after a date change). Not when edited by hand.
     */
    public function rerender(MobileNotification $notification, array $vars): void
    {
        $settings = NotificationTypes::settings((string) $notification->auto_type);

        if (!$settings || $notification->edited_at || !$notification->isScheduled()) {
            return;
        }

        $notification->forceFill([
            'title_en' => NotificationTypes::render($settings->title_en, $vars, 'en'),
            'title_ar' => NotificationTypes::render($settings->title_ar, $vars, 'ar'),
            'body_en' => NotificationTypes::render($settings->body_en, $vars, 'en'),
            'body_ar' => NotificationTypes::render($settings->body_ar, $vars, 'ar'),
            'context' => array_merge($notification->context ?? [], ['vars' => $vars]),
        ])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | SENDING
    |--------------------------------------------------------------------------
    */

    /** Every minute: the scheduled notifications whose date is reached. */
    public function dispatchDue(int $limit = 100): array
    {
        // Sending interrupted (server stopped): closed, never sent twice
        MobileNotification::where('status', 'sending')
            ->where('updated_at', '<', now()->subMinutes(self::SENDING_TIMEOUT_MINUTES))
            ->update(['status' => 'failed', 'last_error' => 'Sending interrupted: use "Send now" to try again.', 'updated_at' => now()]);

        $result = ['sent' => 0, 'cancelled' => 0, 'failed' => 0];

        MobileNotification::due()
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->each(function ($id) use (&$result) {
                $notification = MobileNotification::find($id);

                if (!$notification) {
                    return;
                }

                $this->send($notification);
                $status = $notification->fresh()?->status;

                if (isset($result[$status])) {
                    $result[$status]++;
                }
            });

        return $result;
    }

    /**
     * Sends one scheduled notification now. Returns false when it was not
     * (already sent / taken by another process / no longer needed).
     */
    public function send(MobileNotification $notification): bool
    {
        // Taken by only one process
        $claimed = MobileNotification::whereKey($notification->id)
            ->where('status', 'scheduled')
            ->update(['status' => 'sending', 'last_error' => null, 'updated_at' => now()]);

        if (!$claimed) {
            return false;
        }

        $notification->refresh();

        try {
            // Automatic: still needed? (type disabled, pack renewed...)
            if ($notification->isAuto() && ($reason = $this->noLongerNeeded($notification))) {
                $notification->forceFill([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                ])->save();

                return false;
            }

            // All / with plan / without plan: customers of the moment
            if ($notification->isGroupTarget()) {
                NotificationAudience::syncRecipients(
                    $notification,
                    NotificationAudience::ids($notification->target_type)
                );
            }

            $this->deliver($notification);

            $notification->forceFill(['status' => 'sent', 'sent_at' => now(), 'last_error' => null])->save();

            return true;
        } catch (Throwable $exception) {
            report($exception);

            $notification->forceFill([
                'status' => 'failed',
                'last_error' => Str::limit($exception->getMessage(), 250, ''),
            ])->save();

            return false;
        }
    }

    /** Push to every active phone of each recipient. */
    private function deliver(MobileNotification $notification): void
    {
        $recipients = DB::table('mobile_notification_recipients')
            ->where('notification_id', $notification->id)
            ->whereIn('push_status', ['pending', 'failed'])
            ->whereNull('dismissed_at')
            ->whereNotNull('customer_account_id')
            ->get(['id', 'customer_account_id']);

        if ($recipients->isEmpty()) {
            return;
        }

        $devices = DB::table('mobile_devices')
            ->whereIn('customer_account_id', $recipients->pluck('customer_account_id')->unique()->all())
            ->where('is_active', 1)
            ->whereNotNull('expo_push_token')
            ->where('expo_push_token', '<>', '')
            ->get(['id', 'customer_account_id', 'expo_push_token', 'locale'])
            ->groupBy('customer_account_id');

        $data = [
            'notification_id' => $notification->id,
            'screen' => 'notifications', // old versions of the app
            'destination' => $notification->destination ?: 'notifications',
            'params' => (object) ($notification->destination_params ?? []),
        ];

        $messages = [];
        $owners = [];
        $noDevice = [];

        foreach ($recipients as $recipient) {
            $customerDevices = $devices->get($recipient->customer_account_id, collect());

            if ($customerDevices->isEmpty()) {
                $noDevice[] = $recipient->id;

                continue;
            }

            foreach ($customerDevices as $device) {
                $arabic = $device->locale === 'ar';
                $key = count($messages);

                $messages[$key] = [
                    'device_id' => $device->id,
                    'message' => [
                        'to' => $device->expo_push_token,
                        'sound' => 'default',
                        'title' => $arabic ? ($notification->title_ar ?: $notification->title_en) : ($notification->title_en ?: $notification->title_ar),
                        'body' => $arabic ? ($notification->body_ar ?: $notification->body_en) : ($notification->body_en ?: $notification->body_ar),
                        'data' => $data,
                        'channelId' => 'mchat-high-priority',
                    ],
                ];

                $owners[$key] = ['recipient_id' => $recipient->id, 'device_id' => $device->id];
            }
        }

        // No phone: stays in the list of the app, no push
        if ($noDevice !== []) {
            DB::table('mobile_notification_recipients')
                ->whereIn('id', $noDevice)
                ->update(['push_status' => 'no_device', 'push_error' => null, 'updated_at' => now()]);
        }

        if ($messages === []) {
            return;
        }

        $results = $this->push->send($messages);

        // A recipient is "sent" when at least one of his phones got it
        $byRecipient = [];

        foreach ($owners as $key => $owner) {
            $ok = $results[$key]['ok'] ?? false;
            $current = $byRecipient[$owner['recipient_id']] ?? ['ok' => false, 'device_id' => null, 'error' => null];

            if ($ok && !$current['ok']) {
                $current = ['ok' => true, 'device_id' => $owner['device_id'], 'error' => null];
            } elseif (!$current['ok']) {
                $current['error'] = $results[$key]['error'] ?? 'error';
            }

            $byRecipient[$owner['recipient_id']] = $current;
        }

        foreach ($byRecipient as $recipientId => $state) {
            DB::table('mobile_notification_recipients')
                ->where('id', $recipientId)
                ->update($state['ok']
                    ? ['push_status' => 'sent', 'push_sent_at' => now(), 'mobile_device_id' => $state['device_id'], 'push_error' => null, 'updated_at' => now()]
                    : ['push_status' => 'failed', 'push_error' => Str::limit((string) $state['error'], 250, ''), 'updated_at' => now()]);
        }
    }

    /** Reason to not send an automatic notification any more (null = send it). */
    private function noLongerNeeded(MobileNotification $notification): ?string
    {
        if (!NotificationTypes::exists($notification->auto_type)) {
            return 'Automatic type removed.';
        }

        if (!NotificationTypes::enabled((string) $notification->auto_type)) {
            return 'Automatic type disabled in the settings.';
        }

        if (in_array($notification->auto_type, NotificationTypes::reminders(), true)) {
            return app(ReminderPlanner::class)->noLongerNeeded($notification);
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /** ['en' => 'Milo & Luna', 'ar' => 'Milo و Luna'] */
    public function petNames(Collection $names): array
    {
        $names = $names->map(fn ($name) => trim((string) $name))->filter()->unique()->values();

        return [
            'en' => $names->isEmpty() ? 'your pet' : $names->implode(' & '),
            'ar' => $names->isEmpty() ? 'حيوانك الأليف' : $names->implode(' و '),
        ];
    }

    public function productName(Purchase $purchase): array
    {
        return [
            'en' => (string) ($purchase->product_name ?: $purchase->product_code),
            'ar' => (string) ($purchase->product_name_ar ?: $purchase->product_name ?: $purchase->product_code),
        ];
    }

    private function safely(callable $callback, array $log): ?MobileNotification
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            Log::warning('App notification not created', $log + ['error' => $exception->getMessage()]);

            return null;
        }
    }
}

<?php

namespace App\Services\Notifications;

use App\Models\MobileNotification;
use App\Models\Purchase;
use App\Support\AppNotifications\NotificationTypes;
use Carbon\Carbon;
use Throwable;

/**
 * Reminders before the end of a pack:
 *
 *   plan_ending   paid pack (not trial)  -> renew
 *   trial_ending  trial pack             -> subscribe (trial discount)
 *
 * Planned X days before the last day of the pack (Automatic types
 * settings: "5,1" at 10:00). Run every hour (notifications:plan-reminders)
 * and after each online payment, so they follow the changes:
 *
 *  - end date changed       -> date (and texts) updated, unless edited by hand
 *  - pack cancelled / renewed -> the scheduled reminders are cancelled
 *
 * Last day of the pack = ends_on, else the date of its last delivery.
 */
class ReminderPlanner
{
    public const PLANNED_STATUSES = ['paid', 'active'];

    public function __construct(private NotificationCenter $center)
    {
    }

    /** All the packs in progress. */
    public function planAll(): array
    {
        $result = ['created' => 0, 'updated' => 0, 'cancelled' => 0];

        Purchase::query()
            ->whereIn('status', self::PLANNED_STATUSES)
            ->whereNotNull('customer_account_id')
            ->chunkById(200, function ($purchases) use (&$result) {
                foreach ($purchases as $purchase) {
                    try {
                        foreach ($this->planPurchase($purchase) as $key => $count) {
                            $result[$key] += $count;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });

        // Packs no longer in progress (cancelled, refunded, completed...)
        MobileNotification::query()
            ->where('status', 'scheduled')
            ->whereIn('auto_type', NotificationTypes::reminders())
            ->whereNotNull('purchase_id')
            ->get()
            ->each(function (MobileNotification $notification) use (&$result) {
                if ($reason = $this->noLongerNeeded($notification)) {
                    $this->cancel($notification, $reason);
                    $result['cancelled']++;
                }
            });

        return $result;
    }

    /**
     * Reminders of one pack.
     *
     * @return array{created: int, updated: int, cancelled: int}
     */
    public function planPurchase(Purchase $purchase): array
    {
        $result = ['created' => 0, 'updated' => 0, 'cancelled' => 0];
        $type = $purchase->is_trial ? 'trial_ending' : 'plan_ending';

        if (!$purchase->customer_account_id || !NotificationTypes::exists($type)) {
            return $result;
        }

        $existing = MobileNotification::query()
            ->where('purchase_id', $purchase->id)
            ->where('auto_type', $type)
            ->get()
            ->keyBy('dedupe_key');

        // Not in progress any more, or already renewed: nothing to remind
        if ($reason = $this->reasonToSkip($purchase)) {
            $existing->where('status', 'scheduled')->each(function ($notification) use ($reason, &$result) {
                $this->cancel($notification, $reason);
                $result['cancelled']++;
            });

            return $result;
        }

        if (!NotificationTypes::enabled($type)) {
            return $result;
        }

        $endDate = $this->endDate($purchase);

        if (!$endDate) {
            return $result; // no date yet (deliveries not planned)
        }

        $purchase->loadMissing('animal');
        $sendTime = NotificationTypes::sendTime($type);
        $daysList = NotificationTypes::daysBefore($type);

        // Days removed in the settings ("5,1" -> "3"): their reminders cancelled
        $wantedKeys = array_map(fn ($days) => "{$type}:{$purchase->id}:{$days}", $daysList);

        $existing
            ->filter(fn ($notification) => $notification->isScheduled() && !$notification->edited_at && !in_array($notification->dedupe_key, $wantedKeys, true))
            ->each(function ($notification) use (&$result) {
                $this->cancel($notification, 'Reminder days changed in the settings.');
                $result['cancelled']++;
            });

        foreach ($daysList as $days) {
            $at = Carbon::parse($endDate->toDateString() . ' ' . $sendTime)->subDays($days);
            $key = "{$type}:{$purchase->id}:{$days}";
            $vars = $this->vars($purchase, $endDate, $days);

            /** @var MobileNotification|null $notification */
            $notification = $existing->get($key);

            if ($notification) {
                // Follows the end date (not when changed by hand)
                if ($notification->isScheduled() && !$notification->edited_at && !($notification->scheduled_at && $notification->scheduled_at->equalTo($at))) {
                    if ($at->isPast()) {
                        $this->cancel($notification, 'End date changed: reminder date already passed.');
                        $result['cancelled']++;

                        continue;
                    }

                    $notification->forceFill(['scheduled_at' => $at])->save();
                    $this->center->rerender($notification, $vars);
                    $result['updated']++;
                }

                continue;
            }

            if ($at->isPast()) {
                continue; // never a late reminder
            }

            $created = $this->center->auto($type, (int) $purchase->customer_account_id, $vars, [
                'purchase_id' => $purchase->id,
                'animal_id' => $purchase->animal_id,
                'product_id' => $purchase->product_id,
            ], $at, $key, ['purchase_id' => $purchase->id]);

            if ($created) {
                $result['created']++;
            }
        }

        return $result;
    }

    /** Checked again just before sending. */
    public function noLongerNeeded(MobileNotification $notification): ?string
    {
        $purchase = $notification->purchase_id ? Purchase::find($notification->purchase_id) : null;

        if (!$purchase) {
            return 'Pack not found.';
        }

        return $this->reasonToSkip($purchase);
    }

    public function endDate(Purchase $purchase): ?Carbon
    {
        if ($purchase->ends_on) {
            return Carbon::parse($purchase->ends_on)->startOfDay();
        }

        $last = $purchase->deliveries()->max('delivery_date');

        return $last ? Carbon::parse($last)->startOfDay() : null;
    }

    private function reasonToSkip(Purchase $purchase): ?string
    {
        if (!in_array($purchase->status, self::PLANNED_STATUSES, true)) {
            return 'Pack ' . $purchase->status . '.';
        }

        // A newer pack for the same pet (renewed / subscribed after the trial)
        $renewed = Purchase::query()
            ->where('customer_account_id', $purchase->customer_account_id)
            ->where('id', '>', $purchase->id)
            ->whereIn('status', ['paid', 'active', 'completed'])
            ->where('is_trial', false)
            ->when($purchase->animal_id, fn ($query) => $query->where('animal_id', $purchase->animal_id))
            ->exists();

        return $renewed ? 'The pet already has a newer pack.' : null;
    }

    private function vars(Purchase $purchase, Carbon $endDate, int $days): array
    {
        return [
            'pet' => $this->center->petNames(collect([$purchase->animal?->name ?? $purchase->animal_name])),
            'product' => $this->center->productName($purchase),
            'end_date' => $endDate->format('d/m/Y'),
            'days' => (string) $days,
        ];
    }

    private function cancel(MobileNotification $notification, string $reason): void
    {
        $notification->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => null,
            'cancel_reason' => $reason,
        ])->save();
    }
}

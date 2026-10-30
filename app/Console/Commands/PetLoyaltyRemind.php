<?php

namespace App\Console\Commands;

use App\Http\Controllers\MobileNotificationController;
use App\Models\CustomerAccount;
use App\Models\LoyaltySetting;
use App\Models\MobileNotification;
use App\Models\PetLoyaltyAccount;
use App\Services\PetLoyaltyService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/*
|--------------------------------------------------------------------------
| LOYALTY REMINDERS (push notification to the customer)
|--------------------------------------------------------------------------
|
| php artisan pet-loyalty:remind          (every morning, see the provider)
| php artisan pet-loyalty:remind --dry    (shows what would be sent)
|
|  1. pack_ending     : the pack of the pet ends in X days and the pet has
|                      enough points -> "use your points on the next pack"
|  2. points_expiring : the points of the pet are lost in X days
|
| Never twice for the same pet / kind / date (table loyalty_notifications).
| At most one notification per pet per day.
|
*/

class PetLoyaltyRemind extends Command
{
    protected $signature = 'pet-loyalty:remind {--dry : Show the reminders without sending them}';

    protected $description = 'Send the loyalty points reminders to the customers';

    public function handle(PetLoyaltyService $service): int
    {
        $settings = LoyaltySetting::current();

        if (!$settings->is_enabled || !$settings->reminders_enabled) {
            $this->info('Reminders are disabled in the loyalty settings.');

            return self::SUCCESS;
        }

        $today = Carbon::today();
        $sent = 0;

        $accounts = PetLoyaltyAccount::query()
            ->with('animal')
            ->where('balance', '>', 0)
            ->whereNotNull('subscription_id')
            ->get();

        foreach ($accounts as $account) {
            try {
                $animal = $account->animal;

                if (!$animal) {
                    continue;
                }

                $customer = CustomerAccount::query()->where('subscription_id', $account->subscription_id)->first();

                if (!$customer) {
                    continue; // no app account
                }

                $preview = $service->preview($animal->id);
                $reminder = null;

                // 2. Points lost soon (first: the most urgent)
                if ($account->expires_on) {
                    $daysLeft = (int) $today->diffInDays($account->expires_on, false);

                    if ($daysLeft >= 0 && $daysLeft <= $settings->remind_days_before_expiry) {
                        $reminder = ['kind' => 'points_expiring', 'date' => $account->expires_on->copy(), 'days' => $daysLeft];
                    }
                }

                // 1. Pack ending, points usable on the next one
                if (!$reminder && $preview['usable_points'] > 0) {
                    $last = $service->lastSubscriptionPurchase($animal->id);

                    if ($last) {
                        $end = $service->packEnd($last, $settings);
                        $daysLeft = (int) $today->diffInDays($end, false);

                        if ($daysLeft >= 0 && $daysLeft <= $settings->remind_days_before_end) {
                            $reminder = ['kind' => 'pack_ending', 'date' => $end, 'days' => $daysLeft];
                        }
                    }
                }

                if (!$reminder) {
                    continue;
                }

                if ($this->option('dry')) {
                    $this->line("{$animal->name} (#{$animal->id}): {$reminder['kind']} {$reminder['date']->format('Y-m-d')} · {$account->balance} pts");
                    continue;
                }

                // One per pet / kind / date + one per pet per day
                $alreadyToday = DB::table('loyalty_notifications')
                    ->where('animal_id', $animal->id)
                    ->whereDate('created_at', $today)
                    ->exists();

                if ($alreadyToday) {
                    continue;
                }

                $inserted = DB::table('loyalty_notifications')->insertOrIgnore([
                    'animal_id' => $animal->id,
                    'kind' => $reminder['kind'],
                    'key_date' => $reminder['date']->format('Y-m-d'),
                    'customer_account_id' => $customer->id,
                    'points' => $account->balance,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (!$inserted) {
                    continue; // already sent
                }

                $this->send($customer, $animal->id, $animal->name, $reminder, $account->balance, $preview, $settings);
                $sent++;
            } catch (Throwable $exception) {
                Log::warning('Loyalty reminder failed', ['animal_id' => $account->animal_id, 'error' => $exception->getMessage()]);
            }
        }

        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }

    private function send(CustomerAccount $customer, int $animalId, string $pet, array $reminder, int $balance, array $preview, LoyaltySetting $settings): void
    {
        $texts = $this->texts($pet, $reminder, $balance, $preview, $settings);

        $notification = DB::transaction(function () use ($texts, $customer) {
            $notification = MobileNotification::create([
                'type' => MobileNotification::TYPE_LOYALTY,
                'title_en' => $texts['title_en'],
                'title_ar' => $texts['title_ar'],
                'body_en' => $texts['body_en'],
                'body_ar' => $texts['body_ar'],
                'target_type' => 'selected_customers',
                'status' => 'draft',
            ]);

            DB::table('mobile_notification_recipients')->insert([
                'notification_id' => $notification->id,
                'customer_account_id' => $customer->id,
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

        DB::table('loyalty_notifications')
            ->where('animal_id', $animalId)
            ->where('kind', $reminder['kind'])
            ->where('key_date', $reminder['date']->format('Y-m-d'))
            ->update(['mobile_notification_id' => $notification->id, 'updated_at' => now()]);

        // Push now (same sending as the back-office notifications)
        $response = app(MobileNotificationController::class)->send(
            Request::create('/internal/loyalty-reminder', 'POST'),
            $notification->id
        );

        $result = $response->getData(true);

        if ($response->getStatusCode() >= 400 || !($result['success'] ?? false)) {
            Log::warning('Loyalty reminder push not delivered', ['notification_id' => $notification->id, 'response' => $result]);
        }
    }

    /** Titles and messages (English + Arabic). */
    private function texts(string $pet, array $reminder, int $balance, array $preview, LoyaltySetting $settings): array
    {
        $date = $reminder['date']->format('d/m');
        $amount = number_format((float) $preview['discount'], 3) . ' ' . $settings->currency;

        if ($reminder['kind'] === 'points_expiring') {
            return [
                'title_en' => "{$pet}'s points expire soon ⏳",
                'title_ar' => "نقاط {$pet} تنتهي قريباً ⏳",
                'body_en' => "{$balance} points will be lost after {$date}. Renew {$pet}'s plan to keep them"
                    . ($preview['usable_points'] > 0 ? " and save {$amount}." : '.'),
                'body_ar' => "ستنتهي {$balance} نقطة بعد {$date}. جدّد باقة {$pet} للاحتفاظ بها"
                    . ($preview['usable_points'] > 0 ? " ووفّر {$amount}." : '.'),
            ];
        }

        return [
            'title_en' => "Use {$pet}'s points ⭐",
            'title_ar' => "استخدم نقاط {$pet} ⭐",
            'body_en' => "{$pet}'s pack ends on {$date}. {$preview['usable_points']} points = {$amount} off the next pack.",
            'body_ar' => "تنتهي باقة {$pet} في {$date}. {$preview['usable_points']} نقطة = خصم {$amount} على الباقة القادمة.",
        ];
    }
}

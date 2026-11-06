<?php

namespace App\Console\Commands;

use App\Services\Notifications\ReminderPlanner;
use App\Support\AppNotifications\NotificationTypes;
use Illuminate\Console\Command;

/**
 * Every hour: reminders before the end of the packs (plan / trial ending),
 * created, moved when the end date changes, cancelled when no longer needed.
 */
class NotificationsPlanReminders extends Command
{
    protected $signature = 'notifications:plan-reminders';

    protected $description = 'Plan the "plan ending" / "trial ending" app notifications';

    public function handle(ReminderPlanner $planner): int
    {
        if (!NotificationTypes::installed()) {
            $this->warn('Run database/notifications_v2.sql first.');

            return self::SUCCESS;
        }

        $result = $planner->planAll();

        $this->info("Reminders created: {$result['created']}, moved: {$result['updated']}, cancelled: {$result['cancelled']}.");

        return self::SUCCESS;
    }
}

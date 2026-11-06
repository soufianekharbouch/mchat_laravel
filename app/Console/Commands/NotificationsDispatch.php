<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationCenter;
use App\Support\AppNotifications\NotificationTypes;
use Illuminate\Console\Command;

/**
 * Every minute: sends the scheduled notifications whose date is reached.
 */
class NotificationsDispatch extends Command
{
    protected $signature = 'notifications:dispatch {--limit=100}';

    protected $description = 'Send the scheduled app notifications whose date is reached';

    public function handle(NotificationCenter $center): int
    {
        if (!NotificationTypes::installed()) {
            $this->warn('Run database/notifications_v2.sql first.');

            return self::SUCCESS;
        }

        $result = $center->dispatchDue((int) $this->option('limit'));

        if (array_sum($result) > 0) {
            $this->info("Sent: {$result['sent']}, cancelled (no longer needed): {$result['cancelled']}, failed: {$result['failed']}.");
        }

        return self::SUCCESS;
    }
}

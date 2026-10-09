<?php
namespace App\Console\Commands;

use App\Http\Controllers\MobileNotificationController;
use App\Models\MobileNotification;
use App\Models\SupportTicketMessage;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendUnreadSupportNotifications extends Command
{
    protected $signature = 'support:send-unread-notifications';

    protected $description = 'Notify customers about support messages unread for at least 10 minutes.';

    public function handle(): int
    {
        $processed = 0;
        $failed = 0;

        SupportTicketMessage::query()
            ->where('sender_type', 'admin')
            ->whereNull('read_at')
            ->where('created_at', '<=', now()->subMinutes(10))
            ->orderBy('id')
            ->chunkById(100, function ($messages) use (&$processed, &$failed) {
                foreach ($messages as $message) {
                    try {
                        $notificationId = DB::transaction(function () use ($message) {
                            $locked = SupportTicketMessage::query()
                                ->whereKey($message->id)
                                ->lockForUpdate()
                                ->first();

                            if (
                                !$locked ||
                                $locked->sender_type !== 'admin' ||
                                $locked->read_at !== null ||
                                $locked->created_at->gt(now()->subMinutes(10))
                            ) {
                                return null;
                            }

                            $ticket = DB::table('support_tickets')
                                ->where('id', $locked->support_ticket_id)
                                ->first();

                            if (!$ticket || !$ticket->customer_account_id) {
                                return null;
                            }

                            $existing = DB::table('mobile_notifications')
                                ->where('support_ticket_message_id', $locked->id)
                                ->first();

                            if ($existing) {
                                return null;
                            }

                            $notification = MobileNotification::create([
                                'type' => 'support_ticket',
                                'title_en' => 'New support message',
                                'title_ar' => 'رسالة جديدة من الدعم',
                                'body_en' => 'You have an unread reply from our support team.',
                                'body_ar' => 'لديك رد غير مقروء من فريق الدعم.',
                                'target_type' => 'selected_customers',
                                'status' => 'draft',
                            ]);

                            DB::table('mobile_notifications')
                                ->where('id', $notification->id)
                                ->update([
                                    'support_ticket_message_id' => $locked->id,
                                ]);

                            DB::table('mobile_notification_recipients')
                                ->insert([
                                    'notification_id' => $notification->id,
                                    'customer_account_id' => $ticket->customer_account_id,
                                    'mobile_device_id' => null,
                                    'push_status' => 'pending',
                                    'push_sent_at' => null,
                                    'viewed_at' => null,
                                    'dismissed_at' => null,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                            return $notification->id;
                        });

                        if (!$notificationId) {
                            continue;
                        }

                        $controller = app(MobileNotificationController::class);

                        $response = $controller->send(
                            Request::create('/internal/support-notification', 'POST'),
                            $notificationId
                        );

                        $result = $response->getData(true);

                        if ($response->getStatusCode() >= 400 || !($result['success'] ?? false)) {
                            $failed++;

                            Log::warning('Support notification delivery failed.', [
                                'message_id' => $message->id,
                                'notification_id' => $notificationId,
                                'response' => $result,
                            ]);

                            continue;
                        }

                        $processed++;
                    } catch (Throwable $exception) {
                        $failed++;

                        Log::error('Unread support notification error.', [
                            'message_id' => $message->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Notifications processed: {$processed}. Errors: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

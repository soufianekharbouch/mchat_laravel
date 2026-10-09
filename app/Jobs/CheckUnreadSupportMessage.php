<?php

namespace App\Jobs;

use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckUnreadSupportMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $messageId)
    {
    }

    public function handle(): void
    {
        $message = SupportTicketMessage::query()
            ->whereKey($this->messageId)
            ->where('sender_type', 'admin')
            ->whereNull('read_at')
            ->first();

        if (!$message) {
            return;
        }

        // IMPORTANT: brancher ici le service Laravel existant de notifications.
        // Il doit créer UNE notification liée à $message->support_ticket_id
        // pour le client propriétaire du ticket et, si configuré, envoyer le push.
        // Utiliser une clé unique par message pour éviter les doublons en cas de retry.
        Log::warning('Unread support message notification integration pending', [
            'support_ticket_message_id' => $message->id,
            'support_ticket_id' => $message->support_ticket_id,
        ]);
    }
}

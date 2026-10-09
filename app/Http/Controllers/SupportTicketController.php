<?php

namespace App\Http\Controllers;

use App\Jobs\CheckUnreadSupportMessage;
use App\Models\SupportTicket;
use App\Models\SupportTicketActivity;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    private function authorizeSupport(): void
    {
        $user = auth()->user();
        abort_unless($user && $user->hasPermission('support_tickets.view'), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeSupport();
        $query = SupportTicket::query()->with('customerAccount.subscription')->withCount('messages');

        if ($request->filled('status')) {
            $status = $request->query('status');
            abort_unless(in_array($status, [
                SupportTicket::PENDING, SupportTicket::IN_PROGRESS,
                SupportTicket::RESOLVED, SupportTicket::CLOSED,
            ], true), 422);
            $query->where('status', $status);
        }

        $tickets = $query->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        $unreadCount = SupportTicket::query()
            ->whereHas('messages', fn ($q) => $q->where('sender_type', 'customer')->whereNull('read_at'))
            ->count();

        return view('support-tickets.index', compact('tickets', 'unreadCount'));
    }

    public function show(SupportTicket $ticket)
    {
        $this->authorizeSupport();

        DB::transaction(function () use ($ticket) {
            SupportTicketMessage::where('support_ticket_id', $ticket->id)
                ->where('sender_type', 'customer')->whereNull('read_at')
                ->update(['read_at' => now()]);
            $ticket->update(['admin_last_read_at' => now()]);
        });

        $ticket->load('customerAccount.subscription', 'messages', 'activities');
        return view('support-tickets.show', compact('ticket'));
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $this->authorizeSupport();
        abort_unless(auth()->user()?->hasPermission('support_tickets.reply'), 403);

        $data = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:10000'],
        ])->validate();

        $messageId = DB::transaction(function () use ($ticket, $data) {
            $locked = SupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === SupportTicket::CLOSED) {
                return null;
            }

            $message = SupportTicketMessage::create([
                'support_ticket_id' => $locked->id,
                'sender_type' => 'admin',
                'sender_id' => auth()->id(),
                'message' => $data['message'],
            ]);

            $old = $locked->status;
            $locked->last_message_at = now();
            if ($locked->status === SupportTicket::PENDING) {
                $locked->status = SupportTicket::IN_PROGRESS;
            }
            $locked->save();

            SupportTicketActivity::create([
                'support_ticket_id' => $locked->id,
                'actor_type' => 'admin',
                'actor_id' => auth()->id(),
                'action' => 'replied',
                'old_status' => $old !== $locked->status ? $old : null,
                'new_status' => $old !== $locked->status ? $locked->status : null,
                'created_at' => now(),
            ]);

            return $message->id;
        });

        if ($messageId === null) {
            return back()->withErrors(['message' => 'This ticket is closed.']);
        }

        CheckUnreadSupportMessage::dispatch($messageId)
            ->delay(now()->addMinutes(10));

        return redirect()->route('support-tickets.show', $ticket)
            ->with('success', 'Reply sent.');
    }

    public function updateStatus(Request $request, SupportTicket $ticket)
    {
        $this->authorizeSupport();
        abort_unless(auth()->user()?->hasPermission('support_tickets.update_status'), 403);

        $data = Validator::make($request->all(), [
            'status' => ['required', Rule::in([
                SupportTicket::PENDING, SupportTicket::IN_PROGRESS,
                SupportTicket::RESOLVED, SupportTicket::CLOSED,
            ])],
        ])->validate();

        DB::transaction(function () use ($ticket, $data) {
            $locked = SupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $old = $locked->status;
            if ($old === $data['status']) return;

            $locked->status = $data['status'];
            $locked->resolved_at = $data['status'] === SupportTicket::RESOLVED ? now() : null;
            $locked->closed_at = $data['status'] === SupportTicket::CLOSED ? now() : null;
            $locked->save();

            SupportTicketActivity::create([
                'support_ticket_id' => $locked->id,
                'actor_type' => 'admin',
                'actor_id' => auth()->id(),
                'action' => 'status_changed',
                'old_status' => $old,
                'new_status' => $locked->status,
                'created_at' => now(),
            ]);
        });

        return redirect()->route('support-tickets.show', $ticket)
            ->with('success', 'Status updated.');
    }

    public function unreadCount()
    {
        $this->authorizeSupport();
        return response()->json([
            'success' => true,
            'unread_count' => SupportTicket::whereHas('messages', fn ($q) =>
                $q->where('sender_type', 'customer')->whereNull('read_at'))
                ->count(),
        ]);
    }

    public function messages(SupportTicket $ticket)
    {
        $this->authorizeSupport();
        $ticket->load([
            'messages' => fn ($query) => $query->orderBy('id'),
        ]);

        return response()->json([
            'messages' => $ticket->messages->map(fn ($message) => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'message' => $message->message,
                'created_at_label' => $message->created_at?->format('d M Y, H:i'),
            ])->values(),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\SupportTicket;
use App\Models\SupportTicketActivity;
use App\Models\SupportTicketMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MobileSupportTicketController extends Controller
{
    private function customer(Request $request): ?CustomerAccount
    {
        $token = trim((string) $request->input('access_token', ''));
        if ($token === '') {
            return null;
        }

        return CustomerAccount::query()
            ->where('access_token_hash', hash('sha256', $token))
            ->whereNotNull('access_token_expires_at')
            ->where('access_token_expires_at', '>', now())
            ->first();
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Invalid or expired session.'], 401);
    }

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (!$customer) return $this->unauthorized();

        $tickets = SupportTicket::query()
            ->where('customer_account_id', $customer->id)
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json(['success' => true, 'tickets' => $tickets]);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (!$customer) return $this->unauthorized();

        $data = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:10000'],
        ])->validate();

        $ticket = DB::transaction(function () use ($customer, $data) {
            $now = now();
            $ticket = SupportTicket::create([
                'customer_account_id' => $customer->id,
                'title' => $data['title'],
                'status' => SupportTicket::PENDING,
                'last_message_at' => $now,
            ]);
            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_type' => 'customer',
                'sender_id' => $customer->id,
                'message' => $data['message'],
            ]);
            SupportTicketActivity::create([
                'support_ticket_id' => $ticket->id,
                'actor_type' => 'customer',
                'actor_id' => $customer->id,
                'action' => 'created',
                'new_status' => SupportTicket::PENDING,
                'created_at' => $now,
            ]);
            return $ticket;
        });

        return response()->json([
            'success' => true,
            'message' => 'Support ticket created successfully.',
            'ticket' => $ticket->load('messages', 'activities'),
        ], 201);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $customer = $this->customer($request);
        if (!$customer) return $this->unauthorized();
        if ((int) $ticket->customer_account_id !== (int) $customer->id) {
            return response()->json(['success' => false, 'message' => 'Ticket not found.'], 404);
        }

        DB::transaction(function () use ($ticket) {
            SupportTicketMessage::where('support_ticket_id', $ticket->id)
                ->where('sender_type', 'admin')->whereNull('read_at')
                ->update(['read_at' => now()]);
        });

        return response()->json([
            'success' => true,
            'ticket' => $ticket->load('messages', 'activities'),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $customer = $this->customer($request);
        if (!$customer) return $this->unauthorized();
        if ((int) $ticket->customer_account_id !== (int) $customer->id) {
            return response()->json(['success' => false, 'message' => 'Ticket not found.'], 404);
        }

        $data = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:10000'],
        ])->validate();

        $result = DB::transaction(function () use ($ticket, $customer, $data) {
            $locked = SupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === SupportTicket::CLOSED) return null;

            $message = SupportTicketMessage::create([
                'support_ticket_id' => $locked->id,
                'sender_type' => 'customer',
                'sender_id' => $customer->id,
                'message' => $data['message'],
            ]);
            $oldStatus = $locked->status;
            $locked->last_message_at = now();
            $locked->admin_last_read_at = null;
            if ($oldStatus === SupportTicket::RESOLVED) {
                $locked->status = SupportTicket::PENDING;
                $locked->resolved_at = null;
            }
            $locked->save();
            SupportTicketActivity::create([
                'support_ticket_id' => $locked->id,
                'actor_type' => 'customer',
                'actor_id' => $customer->id,
                'action' => 'replied',
                'old_status' => $oldStatus !== $locked->status ? $oldStatus : null,
                'new_status' => $oldStatus !== $locked->status ? $locked->status : null,
                'created_at' => now(),
            ]);
            return $message;
        });

        if (!$result) {
            return response()->json(['success' => false, 'message' => 'This ticket is closed.'], 409);
        }
        return response()->json(['success' => true, 'message' => $result], 201);
    }
}

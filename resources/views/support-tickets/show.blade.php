@extends('layouts.app')

@section('title', 'Support Ticket #' . $ticket->id)

@push('styles')
<style>
    .ticket-detail .card { border: 1px solid #e9e5ef; border-radius: 14px; overflow: hidden; }
    .ticket-detail .card-header { background: #fff; border-bottom: 1px solid #eee; }
    .ticket-detail .message-row { display: flex; margin-bottom: 18px; }
    .ticket-detail .message-row.admin { justify-content: flex-end; }
    .ticket-detail .message-bubble { max-width: min(85%, 650px); border-radius: 14px; padding: 13px 16px; background: #f1f0f5; overflow-wrap: anywhere; }
    .ticket-detail .message-row.admin .message-bubble { background: #eee4f9; }
    .ticket-detail .message-meta { color: #6b6473; font-size: .75rem; margin-bottom: 6px; }
    .ticket-detail .message-text { white-space: pre-wrap; line-height: 1.55; }
    .ticket-detail .timeline-item { border-left: 2px solid #ddd3e9; padding: 0 0 18px 16px; position: relative; }
    .ticket-detail .timeline-item:before { content: ''; position: absolute; width: 9px; height: 9px; border-radius: 50%; background: #8B5FBF; left: -5.5px; top: 5px; }
    .ticket-detail .timeline-item:last-child { padding-bottom: 0; }
</style>
@endpush

@section('content')
@php
    $customer = $ticket->customerAccount;
    $subscription = $customer?->subscription;
    $customerName = trim(($subscription?->subscriber_first_name ?? '') . ' ' . ($subscription?->subscriber_last_name ?? ''));
    $statuses = ['pending' => 'Pending', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
    $canReply = auth()->user()?->hasPermission('support_tickets.reply') ?? false;
    $canChangeStatus = auth()->user()?->hasPermission('support_tickets.update_status') ?? false;
    $canViewHistory = auth()->user()?->hasPermission('support_tickets.view_history') ?? false;
@endphp
<div class="container-fluid ticket-detail py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <a href="{{ route('support-tickets.index') }}" class="text-decoration-none small"><i class="fas fa-arrow-left me-1"></i>Back to tickets</a>
            <h3 class="mt-2 mb-1">Ticket #{{ $ticket->id }}</h3>
            <div class="text-muted">{{ $ticket->title }}</div>
        </div>
        <span class="badge bg-secondary px-3 py-2">{{ $statuses[$ticket->status] ?? ucfirst($ticket->status) }}</span>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header py-3"><h5 class="mb-0"><i class="fas fa-comments me-2 text-mauve"></i>Conversation</h5></div>
                <div class="card-body p-3 p-md-4" style="max-height:650px;overflow-y:auto" id="ticketConversation">
                    @forelse($ticket->messages as $message)
                        @php $fromAdmin = $message->sender_type === 'admin'; @endphp
                        <div class="message-row {{ $fromAdmin ? 'admin' : 'customer' }}">
                            <div class="message-bubble">
                                <div class="message-meta"><strong>{{ $fromAdmin ? 'Support team' : ($customerName !== '' ? $customerName : 'Customer') }}</strong> · {{ $message->created_at?->format('d M Y, H:i') }}</div>
                                <div class="message-text">{{ $message->message }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted text-center my-5">No messages yet.</p>
                    @endforelse
                </div>
            </div>

            @if($ticket->status === 'closed')
                <div class="alert alert-secondary"><i class="fas fa-lock me-2"></i>This ticket is closed. Change its status to reply.</div>
            @elseif($canReply)
                <div class="card shadow-sm mb-4">
                    <div class="card-header py-3"><h5 class="mb-0">Reply to customer</h5></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('support-tickets.reply', $ticket) }}">
                            @csrf
                            <label for="replyMessage" class="form-label">Message</label>
                            <textarea id="replyMessage" name="message" rows="5" maxlength="10000" required class="form-control @error('message') is-invalid @enderror" placeholder="Write your reply...">{{ old('message') }}</textarea>
                            <div class="text-end mt-3"><button type="submit" class="btn btn-mauve"><i class="fas fa-paper-plane me-2"></i>Send reply</button></div>
                        </form>
                    </div>
                </div>
            @endif

            @if($canViewHistory)
                <div class="card shadow-sm">
                    <div class="card-header py-3"><h5 class="mb-0"><i class="fas fa-history me-2 text-mauve"></i>Activity history</h5></div>
                    <div class="card-body p-4">
                        @forelse($ticket->activities as $activity)
                            <div class="timeline-item">
                                <div class="fw-semibold">{{ ucwords(str_replace('_', ' ', $activity->action)) }}</div>
                                @if($activity->old_status || $activity->new_status)
                                    <div class="small text-muted">{{ $statuses[$activity->old_status] ?? ($activity->old_status ?? '—') }} → {{ $statuses[$activity->new_status] ?? ($activity->new_status ?? '—') }}</div>
                                @endif
                                <div class="small text-muted">{{ ucfirst($activity->actor_type) }} · {{ $activity->created_at?->format('d M Y, H:i') }}</div>
                            </div>
                        @empty
                            <p class="text-muted mb-0">No activity recorded.</p>
                        @endforelse
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header py-3"><h5 class="mb-0">Customer details</h5></div>
                <div class="card-body">
                    <div class="mb-3"><div class="text-muted small">Name</div><strong>{{ $customerName !== '' ? $customerName : 'Customer #' . $ticket->customer_account_id }}</strong></div>
                    <div class="mb-3"><div class="text-muted small">Phone</div>{{ $customer?->phone ?? '—' }}</div>
                    <div class="mb-3"><div class="text-muted small">Email</div>{{ $customer?->email ?? '—' }}</div>
                    <div class="mb-3"><div class="text-muted small">Subscription ID</div>{{ $customer?->subscription_id ?? '—' }}</div>
                    <div class="mb-3"><div class="text-muted small">Created</div>{{ $ticket->created_at?->format('d M Y, H:i') }}</div>
                    <div><div class="text-muted small">Last message</div>{{ $ticket->last_message_at?->format('d M Y, H:i') ?? '—' }}</div>
                </div>
            </div>
            @if($canChangeStatus)
                <div class="card shadow-sm">
                    <div class="card-header py-3"><h5 class="mb-0">Update ticket status</h5></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('support-tickets.status', $ticket) }}">
                            @csrf
                            <label class="form-label" for="ticketStatus">Status</label>
                            <select name="status" id="ticketStatus" class="form-select mb-3" required>
                                @foreach($statuses as $value => $label)
                                    <option value="{{ $value }}" @selected($ticket->status === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-mauve w-100">Save status</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const conversation = document.getElementById('ticketConversation');
    if (!conversation) return;

    const endpoint = @json(route('support-tickets.messages', $ticket));
    const customerName = @json($customerName !== '' ? $customerName : 'Customer');
    let lastSnapshot = null;
    let inFlight = false;
    let active = true;

    conversation.scrollTop = conversation.scrollHeight;

    function renderMessages(messages) {
        const nearBottom = conversation.scrollHeight - conversation.scrollTop - conversation.clientHeight < 100;
        const fragment = document.createDocumentFragment();
        for (const message of messages) {
            const fromAdmin = message.sender_type === 'admin';
            const row = document.createElement('div');
            row.className = 'message-row ' + (fromAdmin ? 'admin' : 'customer');
            const bubble = document.createElement('div');
            bubble.className = 'message-bubble';
            const meta = document.createElement('div');
            meta.className = 'message-meta';
            const author = document.createElement('strong');
            author.textContent = fromAdmin ? 'Support team' : customerName;
            meta.append(author, document.createTextNode(' · ' + (message.created_at_label || '')));
            const body = document.createElement('div');
            body.className = 'message-text';
            body.textContent = message.message || '';
            bubble.append(meta, body);
            row.appendChild(bubble);
            fragment.appendChild(row);
        }
        conversation.replaceChildren(fragment);
        if (nearBottom) conversation.scrollTop = conversation.scrollHeight;
    }

    async function poll() {
        if (!active || inFlight || document.hidden) return;
        inFlight = true;
        try {
            const response = await fetch(endpoint, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const data = await response.json();
            if (!Array.isArray(data.messages)) return;
            const snapshot = JSON.stringify(data.messages);
            if (snapshot !== lastSnapshot) {
                renderMessages(data.messages);
                lastSnapshot = snapshot;
            }
        } catch (error) {
            console.warn('Ticket polling failed:', error);
        } finally {
            inFlight = false;
        }
    }

    poll();
    const timer = setInterval(poll, 10000);
    document.addEventListener('visibilitychange', poll);
    window.addEventListener('pagehide', () => {
        active = false;
        clearInterval(timer);
        document.removeEventListener('visibilitychange', poll);
    }, { once: true });
});
</script>
@endpush


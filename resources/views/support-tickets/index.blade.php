@extends('layouts.app')

@section('title', 'Support Tickets')

@push('styles')
<style>
    .ticket-page .card { border: 1px solid #e9e5ef; border-radius: 14px; }
    .ticket-page .card-header { background: #fff; border-bottom: 1px solid #eee; }
    .ticket-page .table th { color: #6b6473; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
    .ticket-page .table td { vertical-align: middle; }
    .ticket-page .ticket-link { color: #7043a4; text-decoration: none; font-weight: 600; }
    .ticket-page .ticket-link:hover { text-decoration: underline; }
    .ticket-page .status-pill { display: inline-block; padding: .35rem .65rem; border-radius: 50px; font-size: .75rem; font-weight: 700; }
    .ticket-page .status-pending { background: #fff3cd; color: #805b00; }
    .ticket-page .status-in_progress { background: #dceeff; color: #155c98; }
    .ticket-page .status-resolved { background: #dcf5e6; color: #166534; }
    .ticket-page .status-closed { background: #ececf0; color: #555b65; }
</style>
@endpush

@section('content')
<div class="container-fluid ticket-page py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h3 class="mb-1"><i class="fas fa-ticket-alt text-mauve me-2"></i>Support Tickets</h3>
            <div class="text-muted">Manage customer requests and conversations.</div>
        </div>
        <span class="badge bg-danger rounded-pill px-3 py-2">{{ number_format($unreadCount ?? 0) }} unread</span>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form action="{{ route('support-tickets.index') }}" method="GET" class="row g-3 align-items-end">
                <div class="col-md-5 col-lg-4">
                    <label for="status" class="form-label fw-semibold">Ticket status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All statuses</option>
                        @foreach(['pending' => 'Pending', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto"><button class="btn btn-mauve" type="submit"><i class="fas fa-filter me-1"></i>Filter</button></div>
                <div class="col-auto"><a href="{{ route('support-tickets.index') }}" class="btn btn-outline-secondary">Reset</a></div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Tickets</h5>
            <span class="text-muted small">{{ $tickets->total() }} total</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Ticket</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Messages</th>
                        <th>Last activity</th>
                        <th class="text-end pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tickets as $ticket)
                        @php
                            $customer = $ticket->customerAccount;
                            $subscription = $customer?->subscription;
                            $name = trim(($subscription?->subscriber_first_name ?? '') . ' ' . ($subscription?->subscriber_last_name ?? ''));
                            $statusLabel = ['pending' => 'Pending', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'][$ticket->status] ?? ucfirst($ticket->status);
                        @endphp
                        <tr>
                            <td class="ps-3">
                                <a class="ticket-link" href="{{ route('support-tickets.show', $ticket) }}">#{{ $ticket->id }} — {{ $ticket->title }}</a>
                                <div class="text-muted small">Created {{ $ticket->created_at?->format('d M Y, H:i') }}</div>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $name !== '' ? $name : 'Customer #' . $ticket->customer_account_id }}</div>
                                <div class="text-muted small">{{ $customer?->phone ?? $customer?->email ?? '—' }}</div>
                            </td>
                            <td><span class="status-pill status-{{ $ticket->status }}">{{ $statusLabel }}</span></td>
                            <td>{{ $ticket->messages_count ?? 0 }}</td>
                            <td class="text-muted small">{{ ($ticket->last_message_at ?? $ticket->created_at)?->format('d M Y, H:i') }}</td>
                            <td class="text-end pe-3"><a href="{{ route('support-tickets.show', $ticket) }}" class="btn btn-sm btn-outline-primary">Open <i class="fas fa-arrow-right ms-1"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x d-block mb-3"></i>No support tickets found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($tickets->hasPages())
            <div class="card-footer bg-white py-3">{{ $tickets->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection

@extends('layouts.app')

@section('title', 'Notification #' . $notification->id)

@section('content')
@php
    $status = $notification->statusConfig();
    $date = $notification->displayDate();
    $audience = \App\Services\Notifications\NotificationAudience::class;
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a href="{{ route('notifications.index') }}" class="text-decoration-none small"><i class="fas fa-arrow-left me-1"></i>Notifications</a>
        <h2 class="text-mauve mb-0">
            Notification #{{ $notification->id }}
            <span class="badge bg-{{ $status['color'] }} fs-6 align-middle {{ $status['color'] === 'light' ? 'text-dark border' : '' }}">
                <i class="fas {{ $status['icon'] }} me-1"></i>{{ $status['label'] }}
            </span>
        </h2>
    </div>

    @if($canManage && $notification->source)
        <div class="d-flex flex-wrap gap-2">
            @if($notification->isEditable())
                <a href="{{ route('notifications.edit', $notification) }}" class="btn btn-outline-primary">
                    <i class="fas fa-pen me-1"></i>Edit
                </a>
            @endif

            @if(in_array($notification->status, ['scheduled', 'failed', 'cancelled'], true))
                <form method="POST" action="{{ route('notifications.send-now', $notification) }}"
                      onsubmit="return confirm('Send this notification now?');">
                    @csrf
                    <button class="btn btn-mauve"><i class="fas fa-paper-plane me-1"></i>Send now</button>
                </form>
            @endif

            @if($notification->isScheduled() || $notification->status === 'failed')
                <form method="POST" action="{{ route('notifications.cancel', $notification) }}"
                      onsubmit="return confirm('Cancel this notification? It will not be sent.');">
                    @csrf
                    <button class="btn btn-outline-danger"><i class="fas fa-ban me-1"></i>Cancel</button>
                </form>
            @endif

            @if($notification->isCancelled())
                <form method="POST" action="{{ route('notifications.restore', $notification) }}">
                    @csrf
                    <button class="btn btn-outline-success"><i class="fas fa-undo me-1"></i>Schedule again</button>
                </form>
            @endif
        </div>
    @endif
</div>

@foreach(['success' => 'check-circle', 'error' => 'exclamation-circle'] as $flash => $icon)
    @if(session($flash))
        <div class="alert alert-{{ $flash === 'error' ? 'danger' : 'success' }} alert-dismissible fade show">
            <i class="fas fa-{{ $icon }} me-1"></i>{{ session($flash) }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
@endforeach

@if($notification->isCancelled())
    <div class="alert alert-secondary">
        <i class="fas fa-ban me-1"></i>
        Cancelled {{ $notification->cancelled_at?->format('d/m/Y H:i') }}
        @if($notification->cancelledByUser) by {{ $notification->cancelledByUser->name }} @endif
        @if($notification->cancel_reason) — {{ $notification->cancel_reason }} @endif
    </div>
@endif

@if($notification->last_error)
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-1"></i>{{ $notification->last_error }}</div>
@endif

<div class="row g-3 mb-3">
    {{-- Information --}}
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-info-circle me-1"></i>Information</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5">Type</dt>
                    <dd class="col-7">
                        {{ $notification->isAuto() ? 'Automatic' : ($notification->isManual() ? 'Manual' : 'Other') }}
                        · {{ $notification->originLabel() }}
                    </dd>

                    <dt class="col-5">{{ $notification->isSent() ? 'Sent' : 'Sending' }}</dt>
                    <dd class="col-7">
                        {{ ($notification->sent_at ?? $date)?->format('d/m/Y H:i') ?? '—' }}
                        @if($notification->isScheduled() && $date)
                            <div class="text-primary">{{ $date->isPast() ? 'in the next minute' : 'in ' . $date->diffForHumans(null, true) }}</div>
                        @endif
                    </dd>

                    <dt class="col-5">Customer(s)</dt>
                    <dd class="col-7">
                        @if($notification->customerAccount)
                            {{ $audience::label($notification->customerAccount) }}
                        @else
                            {{ $notification->target_label }}
                            <div class="text-muted">{{ (int) $stats->total }} customer(s)
                                @if($notification->isGroupTarget() && $notification->isScheduled()) (computed again when sent) @endif
                            </div>
                        @endif
                    </dd>

                    <dt class="col-5">Opens in the app</dt>
                    <dd class="col-7"><i class="fas fa-mobile-alt me-1"></i>{{ $notification->destinationLabel() }}</dd>

                    @if($notification->purchase_id)
                        <dt class="col-5">Pack</dt>
                        <dd class="col-7">
                            @if(\Illuminate\Support\Facades\Route::has('purchases.show'))
                                <a href="{{ route('purchases.show', $notification->purchase_id) }}">#{{ $notification->purchase_id }}</a>
                            @else
                                #{{ $notification->purchase_id }}
                            @endif
                        </dd>
                    @endif

                    @if($plannedOrderId && \Illuminate\Support\Facades\Route::has('orders.show'))
                        <dt class="col-5">Delivery</dt>
                        <dd class="col-7"><a href="{{ route('orders.show', $plannedOrderId) }}">Order #{{ $plannedOrderId }}</a></dd>
                    @endif

                    <dt class="col-5">Created</dt>
                    <dd class="col-7">
                        {{ $notification->created_at?->format('d/m/Y H:i') }}
                        @if($notification->createdByUser) by {{ $notification->createdByUser->name }} @endif
                    </dd>

                    @if($notification->edited_at)
                        <dt class="col-5">Edited</dt>
                        <dd class="col-7">
                            {{ $notification->edited_at->format('d/m/Y H:i') }}
                            @if($notification->editedByUser) by {{ $notification->editedByUser->name }} @endif
                        </dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    {{-- Content --}}
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-comment-dots me-1"></i>What the customer receives</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="small text-muted mb-1">English</div>
                        <div class="border rounded p-3 bg-light h-100">
                            <div class="fw-semibold">{{ $notification->title_en }}</div>
                            <div class="mt-1" style="white-space: pre-line;">{{ $notification->body_en }}</div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="small text-muted mb-1 text-end">العربية</div>
                        <div class="border rounded p-3 bg-light h-100" dir="rtl">
                            <div class="fw-semibold">{{ $notification->title_ar }}</div>
                            <div class="mt-1" style="white-space: pre-line;">{{ $notification->body_ar }}</div>
                        </div>
                    </div>
                </div>

                <div class="small text-muted mt-3">Each phone receives the language of the app.</div>
            </div>
        </div>
    </div>
</div>

{{-- Counters --}}
@if((int) $stats->total > 0 && !$notification->isScheduled())
    <div class="row g-2 mb-3">
        @foreach([
            ['Customers', $stats->total, 'fa-users', 'secondary'],
            ['Push received', $stats->pushed, 'fa-mobile-alt', 'success'],
            ['No phone', $stats->no_device, 'fa-mobile', 'warning'],
            ['Push failed', $stats->failed, 'fa-times-circle', 'danger'],
            ['Seen', $stats->seen, 'fa-eye', 'info'],
            ['Opened', $stats->opened, 'fa-hand-pointer', 'primary'],
        ] as [$label, $value, $icon, $color])
            <div class="col-6 col-md-2">
                <div class="card card-body py-2 text-center">
                    <div class="small text-muted"><i class="fas {{ $icon }} text-{{ $color }} me-1"></i>{{ $label }}</div>
                    <div class="fs-5 fw-semibold">{{ (int) $value }}</div>
                </div>
            </div>
        @endforeach
    </div>
@endif

{{-- Recipients --}}
<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="fas fa-users me-1"></i>Customers</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Customer</th>
                    <th>Phone (app)</th>
                    <th>Push</th>
                    <th>Seen in the app</th>
                    <th>Opened</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($recipients as $recipient)
                    @php $push = $recipient->pushStatusConfig(); @endphp
                    <tr>
                        <td class="small">
                            @if($recipient->customerAccount)
                                <a href="{{ route('notifications.index', ['tab' => 'all', 'customer_id' => $recipient->customer_account_id]) }}" title="All his notifications">
                                    {{ $audience::label($recipient->customerAccount) }}
                                </a>
                            @else
                                <span class="text-muted">Device only</span>
                            @endif
                        </td>
                        <td class="small text-muted">
                            {{ $recipient->mobileDevice ? (($recipient->mobileDevice->device_name ?: ucfirst($recipient->mobileDevice->platform)) . ' · ' . strtoupper($recipient->mobileDevice->locale ?? '')) : '—' }}
                        </td>
                        <td class="small">
                            @if($notification->isScheduled())
                                <span class="text-muted">Not sent yet</span>
                            @else
                                <span class="badge bg-{{ $push['color'] }}">{{ $push['label'] }}</span>
                                @if($recipient->push_sent_at)
                                    <span class="text-muted">{{ $recipient->push_sent_at->format('d/m H:i') }}</span>
                                @endif
                                @if($recipient->push_error)
                                    <div class="text-danger">{{ $recipient->push_error }}</div>
                                @endif
                            @endif
                        </td>
                        <td class="small">
                            @if($recipient->viewed_at)
                                <i class="fas fa-eye text-info me-1"></i>{{ $recipient->viewed_at->format('d/m/Y H:i') }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                            @if($recipient->dismissed_at)
                                <div class="text-muted"><i class="fas fa-trash-alt fa-xs"></i> deleted by the customer {{ $recipient->dismissed_at->format('d/m H:i') }}</div>
                            @endif
                        </td>
                        <td class="small">
                            @if($recipient->opened_at)
                                <i class="fas fa-check-double text-success me-1"></i>{{ $recipient->opened_at->format('d/m/Y H:i') }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @if($canManage && $notification->isEditable() && !$notification->isGroupTarget() && $recipient->push_status === 'pending' && $stats->total > 0)
                                <form method="POST" action="{{ route('notifications.recipients.destroy', [$notification, $recipient]) }}"
                                      onsubmit="return confirm('Remove this customer from the notification?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Do not send to this customer"><i class="fas fa-user-minus"></i></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            {{ $notification->isGroupTarget() ? 'The customers are chosen when the notification is sent.' : 'No customer.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">
    {{ $recipients->links() }}
</div>
@endsection

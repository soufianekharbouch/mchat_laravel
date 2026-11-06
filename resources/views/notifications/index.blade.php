@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
@php
    $hasFilters = $filters['from'] !== '' || $filters['to'] !== '' || $filters['customer'] !== '' || $filters['customer_id'] > 0 || $filters['type'] !== '' || $filters['read'] !== '';
    $tabIcons = ['upcoming' => 'fa-clock', 'sent' => 'fa-paper-plane', 'failed' => 'fa-exclamation-triangle', 'cancelled' => 'fa-ban', 'all' => 'fa-list'];
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="text-mauve mb-0">Notifications</h2>
        <div class="text-muted small">
            Automatic and manual notifications of the app · server time {{ now()->format('d/m/Y H:i') }}
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
        @if($installed)
            <a href="{{ route('notification-types.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-cogs me-1"></i>Automatic types
            </a>
        @endif

        @if($canManage && $installed)
            <a href="{{ route('notifications.create') }}" class="btn btn-mauve">
                <i class="fas fa-plus me-1"></i>New notification
            </a>
        @endif
    </div>
</div>

@foreach(['success' => 'check-circle', 'error' => 'exclamation-circle'] as $flash => $icon)
    @if(session($flash))
        <div class="alert alert-{{ $flash === 'error' ? 'danger' : 'success' }} alert-dismissible fade show">
            <i class="fas fa-{{ $icon }} me-1"></i>{{ session($flash) }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
@endforeach

@if(!$installed)
    <div class="alert alert-warning">
        <i class="fas fa-database me-1"></i>
        Run <strong>database/notifications_v2.sql</strong> in phpMyAdmin to activate the new notifications.
    </div>
@endif

{{-- Tabs --}}
<ul class="nav nav-tabs mb-3">
    @foreach(\App\Http\Controllers\NotificationController::TABS as $tab => $label)
        <li class="nav-item">
            <a class="nav-link {{ $filters['tab'] === $tab ? 'active' : '' }}"
               href="{{ route('notifications.index', array_merge(request()->except(['tab', 'page']), ['tab' => $tab])) }}">
                <i class="fas {{ $tabIcons[$tab] }} me-1"></i>{{ $label }}
                <span class="badge rounded-pill {{ $tab === 'failed' && $tabCounts[$tab] > 0 ? 'bg-danger' : 'bg-light text-dark border' }}">{{ $tabCounts[$tab] }}</span>
            </a>
        </li>
    @endforeach
</ul>

{{-- Filters --}}
<form method="GET" class="card card-body mb-3 py-2">
    <input type="hidden" name="tab" value="{{ $filters['tab'] }}">

    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">From</label>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control form-control-sm">
        </div>

        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">To</label>
            <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control form-control-sm">
        </div>

        <div class="col-12 col-md-3">
            <label class="form-label small mb-1">Customer</label>
            @if($selectedCustomer)
                <input type="hidden" name="customer_id" value="{{ $selectedCustomer->id }}">
                <div class="input-group input-group-sm">
                    <span class="form-control form-control-sm text-truncate bg-light">{{ \App\Services\Notifications\NotificationAudience::label($selectedCustomer) }}</span>
                    <a href="{{ route('notifications.index', request()->except(['customer_id', 'page'])) }}" class="btn btn-outline-secondary" title="Remove">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            @else
                <input type="text" name="customer" value="{{ $filters['customer'] }}" class="form-control form-control-sm" placeholder="Name, phone, id, code">
            @endif
        </div>

        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Type</label>
            <select name="type" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="manual" @selected($filters['type'] === 'manual')>Manual</option>
                <optgroup label="Automatic">
                    @foreach($types as $key => $type)
                        <option value="{{ $key }}" @selected($filters['type'] === $key)>{{ $type['label'] }}</option>
                    @endforeach
                </optgroup>
                <option value="other" @selected($filters['type'] === 'other')>Other (support, loyalty, old)</option>
            </select>
        </div>

        <div class="col-6 col-md-1">
            <label class="form-label small mb-1">Read</label>
            <select name="read" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="yes" @selected($filters['read'] === 'yes')>Read</option>
                <option value="no" @selected($filters['read'] === 'no')>Not read</option>
            </select>
        </div>

        <div class="col-12 col-md-2 d-flex gap-1">
            <button class="btn btn-sm btn-mauve flex-fill"><i class="fas fa-filter me-1"></i>Filter</button>
            @if($hasFilters)
                <a href="{{ route('notifications.index', ['tab' => $filters['tab']]) }}" class="btn btn-sm btn-outline-secondary" title="Clear">
                    <i class="fas fa-times"></i>
                </a>
            @endif
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 130px;">{{ $filters['tab'] === 'upcoming' ? 'Sending' : 'Date' }}</th>
                    <th>Type</th>
                    <th>Customer</th>
                    <th>Content (what the customer receives)</th>
                    <th>Opens</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Read</th>
                    <th class="text-end" style="width: 120px;"></th>
                </tr>
            </thead>

            <tbody>
                @forelse($notifications as $notification)
                    @php
                        $status = $notification->statusConfig();
                        $date = $notification->displayDate();
                        $single = $notification->customerAccount ?? ($singleRecipients[$notification->id]->customerAccount ?? null);
                    @endphp

                    <tr>
                        <td class="small">
                            @if($date)
                                <div class="fw-semibold">{{ $date->format('d/m/Y') }}</div>
                                <div class="text-muted">{{ $date->format('H:i') }}</div>
                                @if($notification->isScheduled())
                                    <div class="text-primary">{{ $date->isPast() ? 'now' : $date->diffForHumans(null, true) }}</div>
                                @endif
                            @endif
                        </td>

                        <td class="small">
                            @if($notification->isAuto())
                                <span class="badge bg-info-subtle text-info-emphasis border"><i class="fas fa-robot me-1"></i>Auto</span>
                            @elseif($notification->isManual())
                                <span class="badge bg-warning-subtle text-warning-emphasis border"><i class="fas fa-user-edit me-1"></i>Manual</span>
                            @else
                                <span class="badge bg-light text-dark border">Other</span>
                            @endif
                            <div class="mt-1">{{ $notification->originLabel() }}</div>
                            @if($notification->edited_at)
                                <div class="text-muted"><i class="fas fa-pen fa-xs"></i> edited</div>
                            @endif
                        </td>

                        <td class="small">
                            @if($single)
                                <a href="{{ route('notifications.index', array_merge(request()->except(['customer', 'customer_id', 'page']), ['customer_id' => $single->id])) }}"
                                   class="text-decoration-none" title="Only this customer">
                                    {{ \App\Services\Notifications\NotificationAudience::label($single) }}
                                </a>
                            @elseif($notification->recipients_count > 0)
                                <span class="fw-semibold">{{ $notification->recipients_count }} customers</span>
                                <div class="text-muted">{{ $notification->target_label }}</div>
                            @else
                                <span class="text-muted">{{ $notification->target_label }}</span>
                            @endif
                        </td>

                        <td class="small" style="max-width: 340px;">
                            <div class="fw-semibold text-truncate">{{ $notification->title_en }}</div>
                            <div class="text-muted text-truncate">{{ $notification->body_en }}</div>
                            <div class="text-muted text-truncate" dir="rtl">{{ $notification->title_ar }}</div>
                        </td>

                        <td class="small">
                            <i class="fas fa-mobile-alt text-muted me-1"></i>{{ \App\Support\AppNotifications\NotificationDestinations::label($notification->destination) }}
                        </td>

                        <td class="text-center">
                            <span class="badge bg-{{ $status['color'] }} {{ $status['color'] === 'light' ? 'text-dark border' : '' }}">
                                <i class="fas {{ $status['icon'] }} me-1"></i>{{ $status['label'] }}
                            </span>
                            @if($notification->isCancelled() && $notification->cancel_reason)
                                <div class="small text-muted mt-1" title="{{ $notification->cancel_reason }}">{{ \Illuminate\Support\Str::limit($notification->cancel_reason, 30) }}</div>
                            @endif
                            @if($notification->last_error)
                                <div class="small text-danger mt-1" title="{{ $notification->last_error }}">{{ \Illuminate\Support\Str::limit($notification->last_error, 30) }}</div>
                            @endif
                        </td>

                        <td class="text-center small">
                            @if($notification->isSent() && $notification->recipients_count > 0)
                                @if($notification->recipients_count === 1)
                                    @if($notification->opened_count > 0)
                                        <span class="text-success" title="Opened"><i class="fas fa-check-double"></i> Opened</span>
                                    @elseif($notification->read_count > 0)
                                        <span class="text-success" title="Seen in the app"><i class="fas fa-eye"></i> Seen</span>
                                    @else
                                        <span class="text-muted"><i class="far fa-eye-slash"></i> Not yet</span>
                                    @endif
                                @else
                                    <span class="fw-semibold">{{ $notification->read_count }} / {{ $notification->recipients_count }}</span>
                                    <div class="text-muted">{{ $notification->opened_count }} opened</div>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        <td class="text-end text-nowrap">
                            <a href="{{ route('notifications.show', $notification) }}" class="btn btn-sm btn-outline-secondary" title="Details">
                                <i class="fas fa-eye"></i>
                            </a>

                            @if($canManage && $notification->source && $notification->isEditable())
                                <a href="{{ route('notifications.edit', $notification) }}" class="btn btn-sm btn-outline-primary" title="Edit text / date">
                                    <i class="fas fa-pen"></i>
                                </a>
                            @endif

                            @if($canManage && $notification->isScheduled())
                                <form method="POST" action="{{ route('notifications.cancel', $notification) }}" class="d-inline"
                                      onsubmit="return confirm('Cancel this notification? It will not be sent.');">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-danger" title="Cancel"><i class="fas fa-ban"></i></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="far fa-bell-slash fa-2x mb-2 d-block"></i>
                            No notification{{ $hasFilters ? ' with these filters' : '' }}.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">
    {{ $notifications->links() }}
</div>
@endsection

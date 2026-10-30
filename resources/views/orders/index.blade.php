@extends('layouts.app')

@section('title', 'Orders')

@section('content')
@php
    $orderStatuses = \App\Http\Controllers\OrdersController::ORDER_STATUSES;
    $listUrl = fn (array $extra) => route('orders.index', array_merge($filters, $extra));
    $totalCount = array_sum($statusCounts);
    $period = $filters['from'] === '' && $filters['to'] === ''
        ? 'All dates'
        : ($filters['from'] === $filters['to']
            ? \Carbon\Carbon::parse($filters['from'])->translatedFormat('l d/m/Y')
            : \Carbon\Carbon::parse($filters['from'])->format('d/m/Y') . ' → ' . \Carbon\Carbon::parse($filters['to'])->format('d/m/Y'));
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="text-mauve mb-0">
            <i class="fas fa-truck me-2"></i>Orders
        </h2>
        <div class="text-muted small">
            {{ $isToday ? "Today's deliveries" : $period }} · one line = one delivery (same customer, day, slot and address: several pets = one JAAK order)
        </div>
        @if($jaakEnabled)
            <div class="small {{ $openAtJaak && $lastSyncAt && $lastSyncAt->lt(now()->subMinutes(25)) ? 'text-danger' : 'text-muted' }}">
                <i class="fas fa-sync-alt me-1"></i>
                @if($openAtJaak)
                    {{ $openAtJaak }} delivery(ies) followed at JAAK · statuses read automatically every 10 min
                    · last check {{ $lastSyncAt?->diffForHumans() ?? 'never' }}
                    @if($lastSyncAt && $lastSyncAt->lt(now()->subMinutes(25)))
                        (automatic check not running: verify the cPanel cron)
                    @endif
                @else
                    No delivery in progress at JAAK: automatic check paused.
                @endif
            </div>
        @endif
    </div>

    <div class="d-flex flex-wrap gap-2">
        @if($jaakEnabled)
            @if($canSend)
                <a href="{{ route('orders.jaak.check') }}" class="btn btn-outline-secondary" title="Test the JAAK API key">
                    <i class="fas fa-plug"></i>
                </a>
            @endif

            <form method="POST" action="{{ route('orders.jaak.sync') }}">
                @csrf
                <button class="btn btn-outline-secondary" title="Statuses are also read automatically every 10 minutes">
                    <i class="fas fa-sync-alt me-1"></i>Refresh statuses
                </button>
            </form>

            @if($canRequestDrivers)
                <form method="POST" action="{{ route('orders.jaak.request-drivers') }}"
                      onsubmit="return confirm('Request JAAK drivers for the {{ $waitingCount }} delivery(ies) waiting?{{ $waitingPetsCount ? ' (' . $waitingPetsCount . ' other delivery(ies) still wait for pets to be prepared and are not included.)' : '' }} JAAK will charge the delivery fees.');">
                    @csrf
                    <button class="btn btn-mauve" @disabled($waitingCount === 0)>
                        <i class="fas fa-motorcycle me-1"></i>Request drivers
                        <span class="badge bg-light text-dark ms-1">{{ $waitingCount }}</span>
                    </button>
                </form>
            @endif
        @else
            <span class="badge bg-secondary align-self-center p-2">
                <i class="fas fa-plug me-1"></i>JAAK disabled (.env)
            </span>
        @endif
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-1"></i>{{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- DATES + SEARCH --}}
<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('orders.index') }}" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="{{ $filters['status'] }}">
            <input type="hidden" name="jaak" value="{{ $filters['jaak'] }}">
            <input type="hidden" name="slot" value="{{ $filters['slot'] }}">

            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control">
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control">
            </div>

            <div class="col-md-4">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="q" value="{{ $filters['q'] }}" class="form-control"
                       placeholder="DL-261021-1234, MC-D-45, subscription code, name, phone, pet...">
            </div>

            <div class="col-md-auto d-flex flex-wrap gap-1">
                <button class="btn btn-mauve"><i class="fas fa-search me-1"></i>Show</button>

                @php $today = now()->toDateString(); $yesterday = now()->subDay()->toDateString(); $tomorrow = now()->addDay()->toDateString(); @endphp
                <a href="{{ $listUrl(['from' => $yesterday, 'to' => $yesterday]) }}" class="btn btn-outline-secondary">Yesterday</a>
                <a href="{{ $listUrl(['from' => $today, 'to' => $today]) }}" class="btn {{ $isToday ? 'btn-secondary' : 'btn-outline-secondary' }}">Today</a>
                <a href="{{ $listUrl(['from' => $tomorrow, 'to' => $tomorrow]) }}" class="btn btn-outline-secondary">Tomorrow</a>
                <a href="{{ $listUrl(['from' => '', 'to' => '']) }}" class="btn btn-outline-secondary">All history</a>
            </div>
        </form>
    </div>
</div>

{{-- ORDER STATUS --}}
<div class="d-flex flex-wrap gap-2 mb-2">
    <span class="small text-muted align-self-center me-1"><i class="fas fa-box me-1"></i>Deliveries:</span>

    <a href="{{ $listUrl(['status' => '']) }}"
       class="btn btn-sm {{ $filters['status'] === '' ? 'btn-mauve' : 'btn-outline-secondary' }}">
        All <span class="badge bg-light text-dark ms-1">{{ $totalCount }}</span>
    </a>

    @foreach($orderStatuses as $status => $config)
        <a href="{{ $listUrl(['status' => $status]) }}"
           class="btn btn-sm {{ $filters['status'] === $status ? 'btn-' . $config['color'] : 'btn-outline-' . $config['color'] }}">
            <i class="fas {{ $config['icon'] }} me-1"></i>{{ $config['label'] }}
            <span class="badge bg-light text-dark ms-1">{{ $statusCounts[$status] ?? 0 }}</span>
        </a>
    @endforeach
</div>

{{-- DELIVERY SLOT --}}
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <span class="small text-muted me-1"><i class="far fa-clock me-1"></i>Slot:</span>

    <a href="{{ $listUrl(['slot' => '']) }}"
       class="btn btn-sm {{ $filters['slot'] === '' ? 'btn-dark' : 'btn-outline-dark' }}">Any</a>

    @foreach(collect($slots)->merge(array_keys($slotCounts))->filter()->unique()->sort()->values() as $slotValue)
        <a href="{{ $listUrl(['slot' => $slotValue]) }}"
           class="btn btn-sm {{ $filters['slot'] === $slotValue ? 'btn-primary' : 'btn-outline-primary' }}">
            {{ $slotValue }} <span class="badge bg-light text-dark ms-1">{{ $slotCounts[$slotValue] ?? 0 }}</span>
        </a>
    @endforeach

    @if(!empty($slotCounts['']) || $filters['slot'] === 'none')
        <a href="{{ $listUrl(['slot' => 'none']) }}"
           class="btn btn-sm {{ $filters['slot'] === 'none' ? 'btn-danger' : 'btn-outline-danger' }}">
            No slot <span class="badge bg-light text-dark ms-1">{{ $slotCounts[''] ?? 0 }}</span>
        </a>
    @endif
</div>

{{-- JAAK STATUS --}}
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <span class="small text-muted me-1"><i class="fas fa-motorcycle me-1"></i>JAAK:</span>

    <a href="{{ $listUrl(['jaak' => '']) }}"
       class="btn btn-sm {{ $filters['jaak'] === '' ? 'btn-dark' : 'btn-outline-dark' }}">Any</a>

    <a href="{{ $listUrl(['jaak' => 'none']) }}"
       class="btn btn-sm {{ $filters['jaak'] === 'none' ? 'btn-secondary' : 'btn-outline-secondary' }}">
        Not at JAAK
    </a>

    @foreach(\App\Models\JaakDelivery::STATUSES as $status => $config)
        @continue(empty($jaakCounts[$status]) && $filters['jaak'] !== $status)
        <a href="{{ $listUrl(['jaak' => $status]) }}"
           class="btn btn-sm {{ $filters['jaak'] === $status ? 'btn-' . $config['color'] : 'btn-outline-' . $config['color'] }}">
            <i class="fas {{ $config['icon'] }} me-1"></i>{{ $config['label'] }}
            <span class="badge bg-light text-dark ms-1">{{ $jaakCounts[$status] ?? 0 }}</span>
        </a>
    @endforeach
</div>

{{-- LIST: one line per delivery --}}
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Order #</th>
                    <th>Date</th>
                    <th>Delivery slot</th>
                    <th>Customer</th>
                    <th>Zone</th>
                    <th>Pets</th>
                    <th>Status</th>
                    <th>Delivery (JAAK)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($groups as $group)
                    @php
                        $orders = $ordersByGroup->get($group->delivery_key, collect());
                        $first = $orders->first();
                    @endphp
                    @continue(!$first)
                    @php
                        $subscription = $first->subscription;
                        $groupStatus = \App\Support\DeliveryGroup::RANKS[(int) $group->status_rank] ?? 'planned';
                        // planned / prepared with a past date = overdue
                        if (in_array($groupStatus, ['planned', 'prepared'], true) && $first->scheduled_for?->lt(today())) {
                            $groupStatus = 'overdue';
                        }
                        $groupConfig = $orderStatuses[$groupStatus];
                        $groupDeliveries = $orders->map(fn ($o) => $deliveriesByOrder[$o->id] ?? null)->filter()->unique('id')->values();
                        $readyOrders = $orders->filter(fn ($o) => in_array($o->status, ['prepared', 'shipped'], true));
                        $unsentReady = $readyOrders->filter(fn ($o) => !isset($deliveriesByOrder[$o->id]) || $deliveriesByOrder[$o->id]->canBeSent());
                        $waitingDelivery = $groupDeliveries->firstWhere('status', 'waiting');
                        $sendOrder = $unsentReady->first();
                        $slot = $first->delivery_slot_resolved;
                    @endphp
                    <tr>
                        <td class="fw-bold text-nowrap">
                            <a href="{{ route('orders.show', $first) }}" class="text-decoration-none">{{ $numbers[$group->delivery_key] ?? '-' }}</a>
                        </td>
                        <td class="small text-nowrap fw-bold">{{ $first->scheduled_for?->format('d/m/Y') }}</td>
                        <td class="text-nowrap">
                            @if($slot)
                                <span class="badge bg-light text-dark border"><i class="far fa-clock me-1"></i>{{ $slot }}</span>
                            @else
                                <span class="badge bg-danger bg-opacity-75" title="No slot on the address nor on the delivery">No slot</span>
                            @endif
                        </td>
                        <td>
                            <div>{{ trim(($subscription?->subscriber_first_name ?? '') . ' ' . ($subscription?->subscriber_last_name ?? '')) ?: '-' }}</div>
                            <div class="small text-muted">
                                {{ $subscription?->code }}
                                @if($subscription?->subscriber_phone) · {{ $subscription->subscriber_phone }} @endif
                            </div>
                        </td>
                        <td class="small">{{ $subscription?->subscriber_zone ?? '-' }}</td>
                        <td class="small">
                            <span class="badge bg-light text-dark border me-1">
                                <i class="fas fa-paw me-1"></i>{{ $orders->count() }}
                            </span>
                            {{ \Illuminate\Support\Str::limit($orders->map(fn ($o) => $o->animal?->name ?? 'Pet')->implode(', '), 40) }}
                        </td>
                        <td>
                            <span class="badge bg-{{ $groupConfig['color'] }}">
                                <i class="fas {{ $groupConfig['icon'] }} me-1"></i>{{ $groupConfig['label'] }}
                            </span>
                        </td>
                        <td class="small">
                            @forelse($groupDeliveries as $delivery)
                                @php $jaakConfig = $delivery->statusConfig(); @endphp
                                <div class="{{ !$loop->last ? 'mb-1' : '' }}">
                                    <span class="badge bg-{{ $jaakConfig['color'] }}">
                                        <i class="fas {{ $jaakConfig['icon'] }} me-1"></i>{{ $jaakConfig['label'] }}
                                    </span>
                                    @if($groupDeliveries->count() > 1)
                                        <span class="text-muted">{{ $delivery->plannedOrders->count() }} pet(s)</span>
                                    @endif
                                    @if($delivery->max_fee !== null)
                                        <div class="text-muted">{{ $delivery->min_fee }}–{{ $delivery->max_fee }} {{ $delivery->currency }}</div>
                                    @endif
                                    @if($delivery->last_error && in_array($delivery->status, ['queued', 'failed', 'PAUSED'], true))
                                        <div class="text-danger" title="{{ $delivery->last_error }}">
                                            <i class="fas fa-exclamation-circle"></i> {{ \Illuminate\Support\Str::limit($delivery->last_error, 50) }}
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <span class="text-muted">{{ $readyOrders->isNotEmpty() ? 'Not sent' : '-' }}</span>
                            @endforelse
                        </td>
                        <td class="text-end text-nowrap">
                            @if($canSend && $jaakEnabled && $sendOrder)
                                @if($waitingDelivery)
                                    <form method="POST" action="{{ route('orders.jaak.send', $sendOrder) }}" class="d-inline"
                                          onsubmit="return confirm('Send now with the pets already prepared? The others will be delivered separately if they are prepared after the driver left.');">
                                        @csrf
                                        <input type="hidden" name="force" value="1">
                                        <button class="btn btn-sm btn-outline-warning" title="Do not wait for the other pets">
                                            <i class="fas fa-paper-plane"></i> Send now
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('orders.jaak.send', $sendOrder) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-primary" title="Send to JAAK">
                                            <i class="fas fa-paper-plane"></i> {{ $groupDeliveries->isNotEmpty() ? 'Retry' : 'Send' }}
                                        </button>
                                    </form>
                                @endif
                            @endif

                            <a href="{{ route('orders.show', $first) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">
                            <i class="fas fa-truck fa-2x mb-2 d-block"></i>
                            No delivery for {{ $isToday ? 'today' : 'this period' }}.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($groups->hasPages())
        <div class="card-footer">{{ $groups->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection

@extends('layouts.app')

@section('title', 'Order ' . $number)

@section('content')
@php
    $orderStatuses = \App\Http\Controllers\OrdersController::ORDER_STATUSES;
    $subscription = $order->subscription;
    $deliveryByOrder = [];
    foreach ($deliveries as $d) {
        foreach ($d->plannedOrders as $o) {
            $deliveryByOrder[$o->id] = $d;
        }
    }
    $readyOrders = $orders->filter(fn ($o) => in_array($o->status, ['prepared', 'shipped'], true));
    $sendOrder = $readyOrders->first(fn ($o) => !isset($deliveryByOrder[$o->id]) || $deliveryByOrder[$o->id]->canBeSent());
    $waitingDelivery = $deliveries->firstWhere('status', 'waiting');
    $pausedIds = $deliveries->filter(fn ($d) => $d->status === 'PAUSED' && $d->isOnJaak())->pluck('id');
    $onJaakIds = $deliveries->filter(fn ($d) => $d->isOnJaak())->pluck('id');
    $date = $order->scheduled_for?->toDateString();
    // Slot of this delivery (same rule as the grouping)
    $slot = $orders->first()?->delivery_slot_resolved ?: $recipient['slot'];
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a href="{{ route('orders.index', ['from' => $date, 'to' => $date]) }}"
           class="small text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Orders of {{ $order->scheduled_for?->format('d/m/Y') }}</a>
        <h2 class="text-mauve mb-0">
            {{ $number }} · {{ trim(($subscription?->subscriber_first_name ?? '') . ' ' . ($subscription?->subscriber_last_name ?? '')) ?: 'Customer' }}
            @if($orders->count() > 1)
                <span class="badge bg-primary fs-6 align-middle">{{ $orders->count() }} pets · 1 delivery</span>
            @endif
        </h2>
        <div class="text-muted small">
            {{ $order->scheduled_for?->format('l d/m/Y') }} ·
            @if($slot)
                <span class="badge bg-light text-dark border fs-6"><i class="far fa-clock me-1"></i>{{ $slot }}</span>
            @else
                <span class="badge bg-danger">No delivery slot</span>
            @endif
            @if($subscription)
                ·
                @if(auth()->user()?->hasPermission('subscriptions.show'))
                    <a href="{{ route('subscriptions.show', $subscription) }}">{{ $subscription->code }}</a>
                @else
                    {{ $subscription->code }}
                @endif
            @endif
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
        @if($onJaakIds->isNotEmpty())
            <form method="POST" action="{{ route('orders.jaak.sync') }}">
                @csrf
                @foreach($onJaakIds as $id)
                    <input type="hidden" name="delivery_ids[]" value="{{ $id }}">
                @endforeach
                <button class="btn btn-outline-secondary"><i class="fas fa-sync-alt me-1"></i>Refresh status</button>
            </form>
        @endif

        @if($canRequestDrivers && $pausedIds->isNotEmpty())
            <form method="POST" action="{{ route('orders.jaak.request-drivers') }}"
                  onsubmit="return confirm('Request a JAAK driver for this delivery only?');">
                @csrf
                @foreach($pausedIds as $id)
                    <input type="hidden" name="ids[]" value="{{ $id }}">
                @endforeach
                <button class="btn btn-mauve"><i class="fas fa-motorcycle me-1"></i>Request driver</button>
            </form>
        @endif

        @if($canSend && $jaakEnabled && $sendOrder)
            <form method="POST" action="{{ route('orders.jaak.send', $sendOrder) }}"
                  @if($waitingDelivery) onsubmit="return confirm('Send now with the pets already prepared?');" @endif>
                @csrf
                @if($waitingDelivery)
                    <input type="hidden" name="force" value="1">
                @endif
                <button class="btn {{ $waitingDelivery ? 'btn-outline-warning' : 'btn-outline-primary' }}">
                    <i class="fas fa-paper-plane me-1"></i>{{ $waitingDelivery ? 'Send now (without waiting)' : ($deliveries->isNotEmpty() ? 'Send again to JAAK' : 'Send to JAAK') }}
                </button>
            </form>
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

<div class="row g-3">
    {{-- PETS --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold"><i class="fas fa-paw me-2"></i>Orders in this delivery</div>
            <ul class="list-group list-group-flush">
                @foreach($orders as $petOrder)
                    @php
                        $effective = $petOrder->effective_status ?? $petOrder->status;
                        $config = $orderStatuses[$effective] ?? $orderStatuses['planned'];
                    @endphp
                    <li class="list-group-item {{ $petOrder->id === $order->id ? 'bg-light' : '' }}">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="fw-bold">{{ $petOrder->animal?->name ?? 'Pet' }}</span>
                                <span class="text-muted small">{{ $petOrder->animal?->species }} · order #{{ $petOrder->id }}</span>
                            </div>
                            <span class="badge bg-{{ $config['color'] }}"><i class="fas {{ $config['icon'] }} me-1"></i>{{ $config['label'] }}</span>
                        </div>

                        <div class="small mt-1">
                            @forelse($petOrder->items as $item)
                                <div><i class="fas fa-utensils text-muted me-1"></i>{{ $item->recipe?->name ?? 'Recipe #' . $item->recipe_id }}
                                    @if($item->quantity > 1)<span class="text-muted">×{{ $item->quantity }}</span>@endif
                                </div>
                            @empty
                                <div class="text-muted">No recipe.</div>
                            @endforelse
                        </div>

                        <div class="small text-muted mt-1">
                            Prepared {{ $petOrder->prepared_at?->format('d/m H:i') ?? '-' }}
                            · Shipped {{ $petOrder->shipped_at?->format('d/m H:i') ?? '-' }}
                            · Delivered {{ $petOrder->delivered_at?->format('d/m H:i') ?? '-' }}
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    {{-- RECIPIENT --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold"><i class="fas fa-map-marker-alt me-2"></i>Recipient</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-4">Name</dt>
                    <dd class="col-8">{{ $recipient['name'] }}</dd>

                    <dt class="col-4">Phone</dt>
                    <dd class="col-8">{!! $recipient['phone'] ? e($recipient['phone']) : '<span class="text-danger">missing</span>' !!}</dd>

                    <dt class="col-4">Zone</dt>
                    <dd class="col-8">{{ $recipient['zone'] ?? '-' }}</dd>

                    <dt class="col-4">Address</dt>
                    <dd class="col-8">{!! $recipient['address'] ? e($recipient['address']) : '<span class="text-danger">missing</span>' !!}</dd>

                    <dt class="col-4">Delivery slot</dt>
                    <dd class="col-8">{!! $slot ? '<i class="far fa-clock me-1"></i>' . e($slot) : '<span class="text-danger">not set</span>' !!}</dd>

                    @if($recipient['note'])
                        <dt class="col-4">Note</dt>
                        <dd class="col-8">{{ $recipient['note'] }}</dd>
                    @endif
                </dl>

                @if($deliveries->isEmpty() && $readyOrders->isEmpty())
                    <div class="alert alert-light small mt-3 mb-0">
                        Sent to JAAK automatically when all the pets of this delivery are marked <b>Prepared</b> in the daily report.
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- JAAK DELIVERIES (usually one) --}}
    @foreach($deliveries as $delivery)
        @php $jaakConfig = $delivery->statusConfig(); @endphp
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-bold d-flex flex-wrap justify-content-between gap-2">
                    <span>
                        <i class="fas fa-motorcycle me-2"></i>JAAK delivery <code>{{ $delivery->external_id }}</code>
                        <span class="text-muted small fw-normal">· {{ $delivery->plannedOrders->count() }} pet(s)</span>
                    </span>
                    <span class="badge bg-{{ $jaakConfig['color'] }}">
                        <i class="fas {{ $jaakConfig['icon'] }} me-1"></i>{{ $jaakConfig['label'] }}
                    </span>
                </div>

                <div class="card-body">
                    <div class="row small g-2">
                        <div class="col-6 col-md-3"><span class="text-muted">JAAK task</span><br>{{ $delivery->jaak_task_id ? '#' . $delivery->jaak_task_id : '-' }}</div>
                        <div class="col-6 col-md-3"><span class="text-muted">Route (batch)</span><br>{{ $delivery->jaak_batch_id ? '#' . $delivery->jaak_batch_id : '-' }}</div>
                        <div class="col-6 col-md-3"><span class="text-muted">Fee (estimated)</span><br>{{ $delivery->max_fee !== null ? $delivery->min_fee . ' – ' . $delivery->max_fee . ' ' . $delivery->currency : '-' }}</div>
                        <div class="col-6 col-md-3"><span class="text-muted">Last check</span><br>{{ $delivery->last_synced_at?->diffForHumans() ?? '-' }}</div>
                        <div class="col-6 col-md-3"><span class="text-muted">Sent</span><br>{{ $delivery->sent_at?->format('d/m/Y H:i') ?? '-' }}</div>
                        <div class="col-6 col-md-3"><span class="text-muted">Driver requested</span><br>{{ $delivery->drivers_requested_at?->format('d/m/Y H:i') ?? '-' }}</div>
                        <div class="col-6 col-md-3"><span class="text-muted">Picked up</span><br>{{ $delivery->picked_up_at?->format('d/m/Y H:i') ?? '-' }}</div>
                        <div class="col-6 col-md-3"><span class="text-muted">Delivered</span><br>{{ $delivery->delivered_at?->format('d/m/Y H:i') ?? '-' }}</div>
                    </div>

                    @if($delivery->last_error && in_array($delivery->status, ['queued', 'failed', 'PAUSED'], true))
                        <div class="alert alert-danger small mt-3 mb-0">
                            <i class="fas fa-exclamation-triangle me-1"></i>{{ $delivery->last_error }}
                        </div>
                    @endif
                </div>

                <ul class="list-group list-group-flush small">
                    @forelse(array_reverse($delivery->history ?? []) as $entry)
                        <li class="list-group-item">
                            <span class="text-muted me-2">{{ \Carbon\Carbon::parse($entry['at'])->format('d/m H:i') }}</span>
                            {{ $entry['message'] }}
                        </li>
                    @empty
                        <li class="list-group-item text-muted">No event.</li>
                    @endforelse
                </ul>

                @if($delivery->request_payload)
                    <div class="card-footer bg-white">
                        <details>
                            <summary class="small text-muted">Data sent to JAAK</summary>
                            <pre class="small mb-0 mt-2">{{ json_encode(json_decode($delivery->request_payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endsection

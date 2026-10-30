@extends('layouts.app')

@section('title', 'Subscription ' . $subscription->code)

@section('content')
@php
    $orderStatuses = class_exists(\App\Http\Controllers\OrdersController::class)
        ? \App\Http\Controllers\OrdersController::ORDER_STATUSES
        : [];
    $fullName = trim($subscription->subscriber_first_name . ' ' . $subscription->subscriber_last_name);
    $hasOrdersPage = \Illuminate\Support\Facades\Route::has('orders.show') && auth()->user()?->hasPermission('orders.view');
    $activePurchases = $purchases->whereIn('status', ['paid', 'active'])->count();
    $orderBadge = function ($order) use ($orderStatuses) {
        $status = $order->effective_status ?? $order->status;
        $config = $orderStatuses[$status] ?? ['label' => ucfirst($status), 'color' => 'secondary', 'icon' => 'fa-circle'];

        return '<span class="badge bg-' . $config['color'] . '"><i class="fas ' . $config['icon'] . ' me-1"></i>' . e($config['label']) . '</span>';
    };
@endphp

{{-- HEADER --}}
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <a href="{{ route('subscriptions.index') }}" class="small text-decoration-none">
            <i class="fas fa-arrow-left me-1"></i>Subscriptions
        </a>
        <h2 class="text-mauve mb-0">
            {{ $fullName ?: 'Customer' }}
            <span class="badge bg-light text-dark border fs-6 align-middle">{{ $subscription->code }}</span>
        </h2>
        <div class="text-muted small">
            Customer since {{ $subscription->creation_date?->format('d/m/Y') ?? $subscription->created_at?->format('d/m/Y') ?? '-' }}
            @if($subscription->shopify_customer_id) · <i class="fab fa-shopify"></i> {{ $subscription->shopify_customer_id }} @endif
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">

        @if($canUpdate)
            <a href="{{ route('subscriptions.edit', $subscription) }}" class="btn btn-mauve">
                <i class="fas fa-edit me-1"></i>Edit
            </a>
        @endif
    </div>
</div>

{{-- KPIs --}}
<div class="row g-3 mb-3">
    @foreach([
        ['Pets', $subscription->animals->count(), 'fa-paw', 'primary'],
        ['Active packs', $activePurchases, 'fa-box-open', 'success'],
        ['Delivered orders', $orderCounts['delivered'] ?? 0, 'fa-check-circle', 'info'],
        ['Upcoming orders', $upcomingOrders->count() >= 20 ? '20+' : $upcomingOrders->count(), 'fa-calendar', 'warning'],
    ] as [$label, $value, $icon, $color])
        <div class="col-6 col-md">
            <div class="card shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="small text-muted"><i class="fas {{ $icon }} me-1"></i>{{ $label }}</div>
                    <div class="fs-4 fw-bold">{{ $value }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-3">
    {{-- CONTACT --}}
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold"><i class="fas fa-user me-2"></i>Contact & delivery</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Phone</dt>
                    <dd class="col-7">
                        @if($subscription->subscriber_phone)
                            <a href="https://wa.me/{{ preg_replace('/\D+/', '', $subscription->subscriber_phone) }}" target="_blank" class="text-decoration-none">
                                <i class="fab fa-whatsapp text-success me-1"></i>{{ $subscription->subscriber_phone }}
                            </a>
                        @else - @endif
                    </dd>

                    <dt class="col-5">Province</dt>
                    <dd class="col-7">{{ $subscription->subscriber_province ?: '-' }}</dd>

                    <dt class="col-5">Zone</dt>
                    <dd class="col-7">{{ $subscription->subscriber_zone ?: '-' }}</dd>

                    <dt class="col-5">Address</dt>
                    <dd class="col-7">{{ $subscription->subscriber_address ?: '-' }}</dd>

                    <dt class="col-5">Delivery slot</dt>
                    <dd class="col-7">
                        @if($subscription->subscriber_delivery_slot)
                            <span class="badge bg-light text-dark border"><i class="far fa-clock me-1"></i>{{ $subscription->subscriber_delivery_slot }}</span>
                        @else
                            <span class="text-danger">Not set</span>
                        @endif
                    </dd>

                    @if($subscription->subscriber_note)
                        <dt class="col-5">Note</dt>
                        <dd class="col-7">{{ $subscription->subscriber_note }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    {{-- APP ACCOUNT --}}
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold"><i class="fas fa-mobile-alt me-2"></i>App account</div>
            <div class="card-body">
                @if($account)
                    <dl class="row mb-0 small">
                        <dt class="col-5">Login phone</dt>
                        <dd class="col-7">{{ $account->phone }}</dd>

                        <dt class="col-5">Email</dt>
                        <dd class="col-7">{{ $account->email ?: '-' }}</dd>

                        <dt class="col-5">Last login</dt>
                        <dd class="col-7">{{ $account->last_login_at?->format('d/m/Y H:i') ?? 'Never' }}</dd>

                        <dt class="col-5">Logins</dt>
                        <dd class="col-7">{{ $account->login_count ?? 0 }}</dd>

                        <dt class="col-5">Last activity</dt>
                        <dd class="col-7">{{ $account->last_app_activity_at?->diffForHumans() ?? '-' }}</dd>

                        <dt class="col-5">Time in app</dt>
                        <dd class="col-7">
                            @php $seconds = (int) ($account->total_app_seconds ?? 0); @endphp
                            {{ $seconds >= 3600 ? floor($seconds / 3600) . ' h ' : '' }}{{ floor(($seconds % 3600) / 60) }} min
                        </dd>
                    </dl>
                @else
                    <div class="text-muted small">No app account for this customer.</div>
                @endif
            </div>
        </div>
    </div>

    {{-- ADDRESSES --}}
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold"><i class="fas fa-map-marker-alt me-2"></i>Addresses</div>
            <ul class="list-group list-group-flush small">
                @forelse($account?->addresses ?? [] as $address)
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold">{{ $address->label ?: 'Address' }}</span>
                            @if($address->is_default)<span class="badge bg-success">Default</span>@endif
                        </div>
                        <div>{{ $address->address }}</div>
                        <div class="text-muted">
                            {{ $address->zone?->name ?? $address->city }}{{ ($address->zone?->province?->name ?? $address->province) ? ', ' . ($address->zone?->province?->name ?? $address->province) : '' }}
                            @if($address->delivery_slot) · <i class="far fa-clock"></i> {{ $address->delivery_slot }} @else · <span class="text-danger">no slot</span> @endif
                            @if($address->phone) · {{ $address->phone }} @endif
                        </div>
                    </li>
                @empty
                    <li class="list-group-item text-muted">No saved address.</li>
                @endforelse
            </ul>
        </div>
    </div>

    {{-- PETS --}}
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-bold"><i class="fas fa-paw me-2"></i>Pets</div>
            <div class="card-body">
                <div class="row g-3">
                    @forelse($subscription->animals as $animal)
                        <div class="col-md-6 col-xl-4">
                            <div class="border rounded p-3 h-100 d-flex gap-3">
                                @if($animal->photo_path)
                                    <img src="{{ asset('storage/' . $animal->photo_path) }}" alt=""
                                         class="rounded-circle flex-shrink-0" style="width:56px;height:56px;object-fit:cover">
                                @else
                                    <div class="rounded-circle bg-light d-flex align-items-center justify-content-center flex-shrink-0" style="width:56px;height:56px">
                                        <i class="fas {{ strtolower((string) $animal->species) === 'dog' ? 'fa-dog' : 'fa-cat' }} text-muted fs-4"></i>
                                    </div>
                                @endif

                                <div class="small flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-bold fs-6">{{ $animal->name ?: 'Pet #' . $animal->id }}</span>
                                        <span class="badge bg-{{ $animal->hasActiveSubscription() ? 'success' : 'secondary' }}">
                                            {{ $animal->hasActiveSubscription() ? 'Active' : 'Inactive' }}
                                        </span>
                                    </div>
                                    <div class="text-muted">
                                        {{ collect([$animal->species, $animal->breed, $animal->breed_size_category, $animal->age, $animal->sex, $animal->weight_kg ? rtrim(rtrim((string) $animal->weight_kg, '0'), '.') . ' kg' : null])->filter()->implode(' · ') ?: '-' }}
                                    </div>
                                    @if($animal->health_status)<div><b>Health:</b> {{ $animal->health_status }}</div>@endif
                                    @if($animal->allergies)<div><b>Allergies:</b> {{ $animal->allergies }}</div>@endif
                                    @if($animal->preferences)<div><b>Preferences:</b> {{ $animal->preferences }}</div>@endif
                                    @if($animal->note)<div class="text-muted">{{ $animal->note }}</div>@endif
                                    @if($animal->subscription_start || $animal->subscription_end)
                                        <div class="text-muted">
                                            <i class="far fa-calendar me-1"></i>{{ $animal->subscription_start?->format('d/m/Y') ?? '…' }} → {{ $animal->subscription_end?->format('d/m/Y') ?? '…' }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-12 text-muted small">No pet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- PURCHASES --}}
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-bold"><i class="fas fa-shopping-cart me-2"></i>Purchases (packs)</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Reference</th>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Pet</th>
                            <th>Deliveries</th>
                            <th class="text-end">Amount</th>
                            <th>Payment</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($purchases as $purchase)
                            <tr>
                                <td class="fw-semibold text-nowrap">
                                    <a href="{{ route('purchases.show', $purchase) }}" class="text-decoration-none">{{ $purchase->reference ?: '#' . $purchase->id }}</a>
                                    @if($purchase->is_trial)<span class="badge bg-info ms-1">Trial</span>@endif
                                    @if($purchase->shopify_order_name)<div class="text-muted"><i class="fab fa-shopify"></i> {{ $purchase->shopify_order_name }}</div>@endif
                                </td>
                                <td class="text-nowrap">{{ $purchase->purchased_at?->format('d/m/Y') }}</td>
                                <td>{{ $purchase->product_name }}</td>
                                <td>{{ $purchase->animal_name ?: '-' }}</td>
                                <td>{{ $purchase->delivered_count }} / {{ $purchase->deliveries_count }} <span class="text-muted">(max {{ $purchase->max_deliveries }})</span></td>
                                <td class="text-end text-nowrap">{{ number_format((float) $purchase->total_amount, 3) }} {{ $purchase->currency }}</td>
                                <td>{{ strtoupper((string) $purchase->payment_method) ?: '-' }}</td>
                                <td>
                                    <span class="badge bg-{{ \App\Models\Purchase::statusColor($purchase->status) }}">
                                        {{ \App\Models\Purchase::statusLabel($purchase->status) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-3">No purchase.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- DELIVERIES --}}
    @foreach([['Upcoming deliveries', $upcomingOrders, 'fa-calendar-alt'], ['Last deliveries', $pastOrders, 'fa-history']] as [$title, $list, $icon])
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-bold"><i class="fas {{ $icon }} me-2"></i>{{ $title }}</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Pet</th>
                                <th>Recipes</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($list as $order)
                                <tr>
                                    <td class="text-nowrap">
                                        @if($hasOrdersPage)
                                            <a href="{{ route('orders.show', $order) }}" class="text-decoration-none">{{ $order->scheduled_for?->format('d/m/Y') }}</a>
                                        @else
                                            {{ $order->scheduled_for?->format('d/m/Y') }}
                                        @endif
                                    </td>
                                    <td>{{ $order->animal?->name ?? '-' }}</td>
                                    <td class="text-muted">{{ $order->items->sum('quantity') ?: $order->items->count() }} recipe(s)</td>
                                    <td>{!! $orderBadge($order) !!}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">None.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach

</div>
@endsection

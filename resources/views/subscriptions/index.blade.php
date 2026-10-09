@extends('layouts.app')

@section('content')
@php
    $authenticatedUser = auth()->user();

    $canViewSubscriptions = $authenticatedUser
        && $authenticatedUser->hasPermission('subscriptions.view');

    $canCreateSubscription = $authenticatedUser
        && $authenticatedUser->hasPermission('subscriptions.create');

    $canUpdateSubscription = $authenticatedUser
        && $authenticatedUser->hasPermission('subscriptions.update');

    $canDeleteSubscription = $authenticatedUser
        && $authenticatedUser->hasPermission('subscriptions.delete');

    $today = \Carbon\Carbon::today()->toDateString();
@endphp

@if(!$canViewSubscriptions)
    <div class="alert alert-danger">
        <i class="fas fa-lock me-2"></i>
        You do not have permission to view subscriptions.
    </div>
@else

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="text-mauve mb-0">Subscriptions Management</h2>
    @if($canCreateSubscription)
        <a href="{{ route('subscriptions.create') }}" class="btn btn-mauve">
            <i class="fas fa-plus me-2"></i>Add Subscription
        </a>
    @endif
</div>

<div class="card shadow mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label small mb-1">Search (Code / Name / Phone)</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" id="subSearch" class="form-control" placeholder="Type to search...">
                </div>
            </div>

            <div class="col-md-4">
                <label class="form-label small mb-1">Subscription Status</label>
                <select id="subStatusFilter" class="form-select">
                    <option value="all" selected>All</option>
                    <option value="valid">Valid only</option>
                    <option value="invalid">Not valid only</option>
                </select>
            </div>

            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-secondary" id="subResetBtn" type="button">
                    <i class="fas fa-undo me-1"></i>Reset
                </button>
            </div>

            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="small text-muted" id="subCountLabel">Showing 0</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped align-middle" id="subscriptionsTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Subscriber</th>
                        <th>Address</th>
                        <th>Upcoming deliveries</th>
                        <th class="text-center">Total orders</th>
                        @if($canUpdateSubscription || $canDeleteSubscription)
                            <th class="text-end">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($subscriptions as $subscription)
                        @php
                            $hasValidPremium = method_exists($subscription, 'hasActivePremium')
                                ? $subscription->hasActivePremium(\Carbon\Carbon::today())
                                : false;

                            $upcomingOrders = collect($subscription->plannedOrders ?? [])
                                ->filter(function($o) use ($today) {
                                    $date = $o->scheduled_for ? \Carbon\Carbon::parse($o->scheduled_for)->toDateString() : null;
                                    if (!$date) return false;
                                    if ($date < $today) return false;
                                    return in_array($o->status, ['planned','overdue','prepared','shipped'], true);
                                })
                                ->sortBy(function($o){
                                    return \Carbon\Carbon::parse($o->scheduled_for)->toDateString();
                                })
                                ->values();

                            $upcomingByDate = $upcomingOrders->groupBy(function($o){
                                return \Carbon\Carbon::parse($o->scheduled_for)->toDateString();
                            });

                            $upcomingDates = $upcomingByDate->keys()->values()->toArray();

                            $subscriptionValid = count($upcomingDates) > 0;

                            $lockEditing = collect($subscription->plannedOrders ?? [])
                                ->contains(function($o){
                                    return in_array($o->status, ['overdue','prepared','shipped','delivered'], true);
                                });

                            $allOrders = collect($subscription->plannedOrders ?? [])
                                ->filter(function($o){
                                    return !empty($o->scheduled_for);
                                })
                                ->values();

                            $totalOrdersCount = $allOrders->count();

                            $oldOrders = $allOrders
                                ->filter(function($o) use ($today){
                                    $d = \Carbon\Carbon::parse($o->scheduled_for)->toDateString();
                                    return $d < $today;
                                })
                                ->sortByDesc(function($o){
                                    return \Carbon\Carbon::parse($o->scheduled_for)->toDateString();
                                })
                                ->values();

                            $oldDates = $oldOrders
                                ->groupBy(function($o){
                                    return \Carbon\Carbon::parse($o->scheduled_for)->toDateString();
                                })
                                ->keys()
                                ->values()
                                ->toArray();

                            $ordersByDatePayload = [];
                            foreach ($upcomingByDate as $dateKey => $ordersForDate) {
                                $ordersByDatePayload[$dateKey] = $ordersForDate->map(function($po){
                                    $animal = $po->animal ?? null;

                                    $items = collect($po->items ?? [])->map(function($it){
                                        $recipe = $it->recipe ?? null;
                                        return [
                                            'recipe' => (string)($recipe->name ?? ''),
                                            'qty' => (int)($it->quantity ?? 0),
                                        ];
                                    })->filter(function($it){
                                        return $it['qty'] > 0 && $it['recipe'] !== '';
                                    })->values()->toArray();

                                    return [
                                        'planned_order_id' => $po->id,
                                        'status' => (string)($po->status ?? ''),
                                        'animal' => [
                                            'id' => $animal->id ?? null,
                                            'name' => (string)($animal->name ?? ''),
                                            'species' => (string)($animal->species ?? ''),
                                            'age' => $animal->age ?? null,
                                            'health_status' => (string)($animal->health_status ?? ''),
                                            'note' => (string)($animal->note ?? ''),
                                        ],
                                        'items' => $items,
                                    ];
                                })->values()->toArray();
                            }

                            $payload = [
                                'id' => $subscription->id,
                                'code' => (string) $subscription->code,
                                'subscriber_first_name' => (string) $subscription->subscriber_first_name,
                                'subscriber_last_name' => (string) $subscription->subscriber_last_name,
                                'subscriber_address' => (string) ($subscription->subscriber_address ?? ''),
                                'subscriber_province' => (string) ($subscription->subscriber_province ?? ''),
                                'subscriber_zone' => (string) ($subscription->subscriber_zone ?? ''),
                                'subscriber_phone' => (string) ($subscription->subscriber_phone ?? ''),
                                'premium_active' => $hasValidPremium ? 1 : 0,
                                'subscription_valid' => $subscriptionValid ? 1 : 0,
                                'lock_editing' => $lockEditing ? 1 : 0,
                                'upcoming_dates' => $upcomingDates,
                                'orders_by_date' => $ordersByDatePayload,
                                'total_orders_count' => $totalOrdersCount,
                                'old_dates' => $oldDates,
                            ];

                            $searchIndex = strtolower(
                                trim(
                                    (string)$subscription->code.' '.
                                    (string)$subscription->subscriber_first_name.' '.
                                    (string)$subscription->subscriber_last_name.' '.
                                    (string)($subscription->subscriber_phone ?? '')
                                )
                            );
                        @endphp

                        <tr class="subscription-row"
                            role="button"
                            tabindex="0"
                            data-subscription='@json($payload)'
                            data-search="{{ e($searchIndex) }}"
                            data-valid="{{ $subscriptionValid ? '1' : '0' }}"
                        >
                            <td>
                                <div class="d-flex flex-column">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-semibold">{{ $subscription->code }}</span>

                                        @if($hasValidPremium)
                                            <span class="premium-badge-sub">
                                                <i class="fas fa-crown me-1"></i> Premium
                                            </span>
                                        @endif

                                        @if($subscriptionValid)
                                            <span class="badge bg-success">Valid</span>
                                        @else
                                            <span class="badge bg-secondary">Not valid</span>
                                        @endif
                                    </div>

                                    @if(!empty($subscription->subscriber_phone))
                                        <div class="text-muted small mt-1">
                                            <i class="fas fa-phone me-1"></i>{{ $subscription->subscriber_phone }}
                                        </div>
                                    @endif
                                </div>
                            </td>

                            <td>{{ $subscription->subscriber_first_name }} {{ $subscription->subscriber_last_name }}</td>

                            <td>{{ \Illuminate\Support\Str::limit($subscription->subscriber_address, 30) }}</td>

                            <td>
                                @if(count($upcomingDates) === 0)
                                    <span class="text-muted small">-</span>
                                @else
                                    <div class="upcoming-wrap">
                                        @foreach($upcomingDates as $d)
                                            @php
                                                $label = \Carbon\Carbon::parse($d)->format('M j');
                                            @endphp
                                            <button type="button"
                                                    class="upcoming-date-square"
                                                    data-order-date="{{ e($d) }}"
                                                    data-stop-click>
                                                {{ $label }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </td>

                            <td class="text-center">
                                <span class="badge bg-secondary">{{ $totalOrdersCount }}</span>
                            </td>

                            @if($canUpdateSubscription || $canDeleteSubscription)
                                <td class="subscription-actions text-end">
                                    @if($lockEditing)
                                        @if($canUpdateSubscription)
                                            <a
                                                href="{{ route('subscriptions.edit', $subscription) }}"
                                                class="btn btn-sm btn-outline-primary subscription-action-btn"
                                                data-stop-click
                                                title="Edit subscription"
                                            >
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        @endif

                                        @if($canDeleteSubscription)
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-secondary subscription-action-btn"
                                                disabled
                                                title="This subscription cannot be deleted"
                                            >
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        @endif
                                    @else
                                        @if($canUpdateSubscription)
                                            <a
                                                href="{{ route('subscriptions.edit', $subscription) }}"
                                                class="btn btn-sm btn-outline-primary subscription-action-btn"
                                                data-stop-click
                                                title="Edit subscription"
                                            >
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        @endif

                                        @if($canDeleteSubscription)
                                            <form
                                                action="{{ route('subscriptions.destroy', $subscription) }}"
                                                method="POST"
                                                class="d-inline subscription-action-form"
                                                data-stop-click
                                                onsubmit="return confirm('Are you sure?');"
                                            >
                                                @csrf
                                                @method('DELETE')

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-danger subscription-action-btn"
                                                    title="Delete subscription"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach

                    @if($subscriptions->isEmpty())
                        <tr>
                            <td
                                colspan="{{ ($canUpdateSubscription || $canDeleteSubscription) ? 6 : 5 }}"
                                class="text-center text-muted py-4"
                            >
                                <i class="fas fa-info-circle me-1"></i>No subscriptions found.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>

            <div class="text-center text-muted py-4 d-none" id="subNoResults">
                <i class="fas fa-search me-1"></i>No results with current filters.
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="subscriptionDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-mauve text-white">
                <h5 class="modal-title" id="subModalTitle">Subscription</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="sub-modal-summary mb-3">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <div class="sub-kv">
                                <div class="sub-k">Code</div>
                                <div class="sub-v" id="subCode"></div>
                            </div>
                        </div>

                        <div class="col-md-5">
                            <div class="sub-kv">
                                <div class="sub-k">Subscriber</div>
                                <div class="sub-v" id="subName"></div>
                            </div>
                        </div>

                        <div class="col-md-2">
                            <div class="sub-kv">
                                <div class="sub-k">Status</div>
                                <div class="sub-v" id="subStatus"></div>
                            </div>
                        </div>

                        <div class="col-md-2">
                            <div class="sub-kv">
                                <div class="sub-k">Premium</div>
                                <div class="sub-v" id="subPremium"></div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="sub-kv">
                                <div class="sub-k">Phone</div>
                                <div class="sub-v" id="subPhone"></div>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="sub-kv">
                                <div class="sub-k">Address</div>
                                <div class="sub-v" id="subAddress"></div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="sub-kv">
                                <div class="sub-k">Province / Zone</div>
                                <div class="sub-v" id="subZone"></div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="sub-kv">
                                <div class="sub-k">Upcoming deliveries</div>
                                <div class="sub-v" id="subUpcomingDates"></div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="sub-kv">
                                <div class="sub-k">Old deliveries</div>
                                <div class="sub-v" id="subOldDates"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-muted small">
                    Click a date in the table to view the planned order details for that day.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-mauve text-white">
                <h5 class="modal-title" id="orderModalTitle">Planned Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="mb-2">
                    <span class="badge bg-mauve" id="orderModalDateBadge"></span>
                </div>

                <div class="card card-sm mb-2">
                    <div class="card-header bg-yellow py-1">
                        <strong>Subscriber</strong>
                    </div>
                    <div class="card-body p-2">
                        <div class="row g-2 small">
                            <div class="col-md-6"><strong>Name:</strong> <span id="orderSubName"></span></div>
                            <div class="col-md-6"><strong>Phone:</strong> <span id="orderSubPhone"></span></div>
                            <div class="col-md-6"><strong>Code:</strong> <span id="orderSubCode"></span></div>
                            <div class="col-12"><strong>Address:</strong> <span id="orderSubAddress"></span></div>
                            <div class="col-md-6"><strong>Province:</strong> <span id="orderSubProvince"></span></div>
                            <div class="col-md-6"><strong>Zone:</strong> <span id="orderSubZone"></span></div>
                        </div>
                    </div>
                </div>

                <div class="card card-sm">
                    <div class="card-header bg-yellow py-1">
                        <strong>Orders for this date</strong>
                    </div>
                    <div class="card-body p-2">
                        <div id="orderAnimalsContainer"></div>
                    </div>
                </div>
            </div>

            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.premium-badge-sub {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    font-size: 0.7rem;
    font-weight: 600;
    border-radius: 999px;
    background: linear-gradient(135deg, #f9d976, #f39f86);
    color: #5c420a;
    border: 1px solid rgba(140,110,20,0.4);
    box-shadow: 0 1px 3px rgba(0,0,0,0.15);
    white-space: nowrap;
}
.premium-badge-sub i { color: #b8860b; font-size: 0.75rem; }

.subscription-row td:not(.subscription-actions) { cursor: pointer; }

.sub-modal-summary {
    background: #fafafa;
    border: 1px solid rgba(0,0,0,0.06);
    border-radius: 10px;
    padding: 12px;
}
.sub-kv {
    border: 1px solid rgba(0,0,0,0.06);
    background: #fff;
    border-radius: 10px;
    padding: 10px;
    height: 100%;
}
.sub-k {
    font-size: 0.75rem;
    color: #6c757d;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
}
.sub-v {
    font-size: 0.95rem;
    font-weight: 600;
    color: #212529;
    margin-top: 2px;
    word-break: break-word;
}

.subscription-row { transition: background-color 0.15s ease, box-shadow 0.15s ease; }
.subscription-row:hover {
    background-color: rgba(108, 92, 231, 0.06);
    box-shadow: inset 4px 0 0 #6c5ce7;
}

.upcoming-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.upcoming-date-square {
    width: 54px;
    height: 34px;
    border-radius: 8px;
    border: 1px solid rgba(0,0,0,0.12);
    background: #fff;
    font-size: 0.8rem;
    font-weight: 700;
    color: #5b21b6;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: transform 0.08s ease, box-shadow 0.12s ease, background 0.12s ease;
}
.upcoming-date-square:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.10);
    background: rgba(108,92,231,0.06);
}

.order-animal-card {
    border: 1px solid rgba(0,0,0,0.08);
    border-radius: 10px;
    padding: 10px;
    margin-bottom: 10px;
    background: #fff;
}
.order-animal-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}
.order-animal-title .name {
    font-weight: 800;
}
.order-status-pill {
    font-size: 0.75rem;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 999px;
    border: 1px solid rgba(0,0,0,0.12);
    background: #f8fafc;
}
.order-items-table th, .order-items-table td {
    padding: 6px;
    border-bottom: 1px solid rgba(0,0,0,0.06);
}
.order-items-table th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: #6b7280;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderUpcomingDates(dates) {
        if (!Array.isArray(dates) || dates.length === 0) return '-';
        return dates.map(function(d){
            var dt = new Date(d + 'T00:00:00');
            var label = dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            return '<span class="badge bg-mauve me-1">' + esc(label) + '</span>';
        }).join(' ');
    }

    function renderOldDates(dates) {
        if (!Array.isArray(dates) || dates.length === 0) return '-';
        return dates.map(function(d){
            var dt = new Date(d + 'T00:00:00');
            var label = dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            return '<span class="badge bg-secondary me-1">' + esc(label) + '</span>';
        }).join(' ');
    }

    function statusLabel(status) {
        var s = String(status || '').toLowerCase();
        if (s === 'planned') return 'Planned';
        if (s === 'overdue') return 'Overdue';
        if (s === 'prepared') return 'Prepared';
        if (s === 'shipped') return 'Shipped';
        if (s === 'delivered') return 'Delivered';
        return status || '-';
    }

    function statusClass(status) {
        var s = String(status || '').toLowerCase();
        if (s === 'overdue') return 'bg-danger text-white';
        if (s === 'prepared') return 'bg-warning text-dark';
        if (s === 'shipped') return 'bg-primary text-white';
        if (s === 'delivered') return 'bg-success text-white';
        return 'bg-light text-dark';
    }

    var subModalEl = document.getElementById('subscriptionDetailsModal');
    var subModal = new bootstrap.Modal(subModalEl);

    var orderModalEl = document.getElementById('orderDetailsModal');
    var orderModal = new bootstrap.Modal(orderModalEl);

    function openSubscriptionModalFromRow(row) {
        var raw = row.getAttribute('data-subscription') || '{}';
        var sub = {};
        try { sub = JSON.parse(raw); } catch (e) { sub = {}; }

        document.getElementById('subModalTitle').textContent = 'Subscription ' + (sub.code || '');
        document.getElementById('subCode').textContent = sub.code || '-';
        document.getElementById('subName').textContent = (sub.subscriber_first_name || '') + ' ' + (sub.subscriber_last_name || '');

        document.getElementById('subStatus').innerHTML = sub.subscription_valid
            ? '<span class="badge bg-success">Valid</span>'
            : '<span class="badge bg-secondary">Not valid</span>';

        document.getElementById('subPremium').innerHTML = sub.premium_active
            ? '<span class="badge bg-warning text-dark">Active</span>'
            : '<span class="badge bg-light text-dark border">No</span>';

        document.getElementById('subPhone').textContent = sub.subscriber_phone || '-';
        document.getElementById('subAddress').textContent = sub.subscriber_address || '-';
        document.getElementById('subZone').textContent = (sub.subscriber_province || '-') + ' / ' + (sub.subscriber_zone || '-');
        document.getElementById('subUpcomingDates').innerHTML = renderUpcomingDates(sub.upcoming_dates || []);
        document.getElementById('subOldDates').innerHTML = renderOldDates(sub.old_dates || []);

        subModal.show();
    }

    function openOrderModal(sub, dateStr) {
        var ordersByDate = sub.orders_by_date || {};
        var orders = ordersByDate[dateStr] || [];

        document.getElementById('orderModalTitle').textContent = 'Planned orders';
        document.getElementById('orderModalDateBadge').textContent = 'Date: ' + dateStr;

        document.getElementById('orderSubName').textContent = (sub.subscriber_first_name || '') + ' ' + (sub.subscriber_last_name || '');
        document.getElementById('orderSubPhone').textContent = sub.subscriber_phone || '-';
        document.getElementById('orderSubCode').textContent = sub.code || '-';
        document.getElementById('orderSubAddress').textContent = sub.subscriber_address || '-';
        document.getElementById('orderSubProvince').textContent = sub.subscriber_province || '-';
        document.getElementById('orderSubZone').textContent = sub.subscriber_zone || '-';

        var container = document.getElementById('orderAnimalsContainer');
        container.innerHTML = '';

        if (!Array.isArray(orders) || orders.length === 0) {
            container.innerHTML = '<div class="alert alert-info small mb-0">No planned orders for this date.</div>';
            orderModal.show();
            return;
        }

        orders.forEach(function(o, idx){
            var animal = o.animal || {};
            var items = Array.isArray(o.items) ? o.items : [];

            var card = document.createElement('div');
            card.className = 'order-animal-card';

            var status = statusLabel(o.status);
            var statusBadge = '<span class="badge ' + statusClass(o.status) + '">' + esc(status) + '</span>';

            var title = (animal.name ? esc(animal.name) : ('Animal #' + (idx+1)));
            if (animal.species) title += ' • ' + esc(animal.species);

            var rows = '';
            if (!items.length) {
                rows = '<tr><td class="small text-muted" colspan="2">No items</td></tr>';
            } else {
                rows = items.map(function(it){
                    return '<tr><td class="small">' + esc(it.recipe || '') + '</td><td class="small text-end fw-semibold">' + esc(String(it.qty ?? 0)) + '</td></tr>';
                }).join('');
            }

            card.innerHTML =
                '<div class="order-animal-title">' +
                    '<div class="name">' + title + '</div>' +
                    '<div>' + statusBadge + '</div>' +
                '</div>' +
                '<div class="table-responsive">' +
                    '<table class="table table-sm mb-0 order-items-table">' +
                        '<thead><tr><th>Recipe</th><th class="text-end" style="width:90px;">Qty</th></tr></thead>' +
                        '<tbody>' + rows + '</tbody>' +
                    '</table>' +
                '</div>';

            container.appendChild(card);
        });

        orderModal.show();
    }

    document.addEventListener('click', function(e){
        var stop = e.target.closest('[data-stop-click]');
        if (stop) e.stopPropagation();
    });

    document.querySelectorAll('.subscription-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target && (e.target.closest('.subscription-actions') || e.target.closest('a') || e.target.closest('button') || e.target.closest('form'))) return;
            openSubscriptionModalFromRow(row);
        });

        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openSubscriptionModalFromRow(row);
            }
        });

        row.querySelectorAll('.upcoming-date-square').forEach(function(btn){
            btn.addEventListener('click', function(ev){
                ev.preventDefault();
                ev.stopPropagation();

                var raw = row.getAttribute('data-subscription') || '{}';
                var sub = {};
                try { sub = JSON.parse(raw); } catch (e) { sub = {}; }

                var dateStr = btn.getAttribute('data-order-date') || '';
                if (!dateStr) return;

                openOrderModal(sub, dateStr);
            });
        });
    });

    var searchInput = document.getElementById('subSearch');
    var statusSelect = document.getElementById('subStatusFilter');
    var resetBtn = document.getElementById('subResetBtn');
    var rows = Array.prototype.slice.call(document.querySelectorAll('#subscriptionsTable tbody tr.subscription-row'));
    var countLabel = document.getElementById('subCountLabel');
    var noResults = document.getElementById('subNoResults');

    function applyFilters() {
        var q = (searchInput.value || '').toLowerCase().trim();
        var st = statusSelect.value || 'all';

        var shown = 0;

        rows.forEach(function (row) {
            var hay = (row.getAttribute('data-search') || '').toLowerCase();
            var valid = row.getAttribute('data-valid') === '1';

            var matchQ = !q || hay.indexOf(q) !== -1;
            var matchS = (st === 'all') || (st === 'valid' && valid) || (st === 'invalid' && !valid);

            var ok = matchQ && matchS;

            row.classList.toggle('d-none', !ok);
            if (ok) shown++;
        });

        countLabel.textContent = 'Showing ' + shown + ' / ' + rows.length;
        noResults.classList.toggle('d-none', shown !== 0);
    }

    searchInput.addEventListener('input', applyFilters);
    statusSelect.addEventListener('change', applyFilters);

    resetBtn.addEventListener('click', function () {
        searchInput.value = '';
        statusSelect.value = 'all';
        applyFilters();
    });

    applyFilters();
});
</script>
@endif
@endsection

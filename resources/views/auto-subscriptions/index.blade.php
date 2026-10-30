@extends('layouts.app')

@section('title', 'Auto subscription')

@section('content')
@php
    $listUrl = fn (array $extra) => route('auto-subscriptions.index', array_merge(
        array_filter($filters, fn ($value) => $value !== ''),
        $extra
    ));
    $totalCount = array_sum($statusCounts);
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="text-mauve mb-0">
            <i class="fab fa-shopify me-2"></i>Auto subscription
        </h2>
        <div class="text-muted small">Shopify orders imported automatically (customer, pet, paid purchase, deliveries).</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        @if(auth()->user()?->hasPermission('auto_subscriptions.manage'))
            <form method="POST" action="{{ route('auto-subscriptions.sync') }}" id="syncForm">
                @csrf
                <button class="btn btn-mauve" id="syncButton" title="Read the last Shopify orders and import the ones not received">
                    <i class="fas fa-sync-alt me-1"></i>Refresh
                </button>
            </form>
        @endif

        @if(\Illuminate\Support\Facades\Route::has('auto-subscriptions.test') && auth()->user()?->hasPermission('auto_subscriptions.test'))
            <a href="{{ route('auto-subscriptions.test') }}" class="btn btn-outline-primary">
                <i class="fas fa-vial me-1"></i>Test an order
            </a>
        @endif

        @if($toReviewCount > 0)
            <a href="{{ $listUrl(['review' => 'todo', 'status' => '']) }}" class="btn btn-warning">
                <i class="fas fa-bell me-1"></i>{{ $toReviewCount }} to check
            </a>
        @endif
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($errors->has('sync'))
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-1"></i>{{ $errors->first('sync') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- STATUS --}}
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ $listUrl(['status' => '', 'review' => '']) }}"
       class="btn btn-sm {{ $filters['status'] === '' && $filters['review'] === '' ? 'btn-mauve' : 'btn-outline-secondary' }}">
        All <span class="badge bg-light text-dark ms-1">{{ $totalCount }}</span>
    </a>

    <a href="{{ $listUrl(['review' => 'todo', 'status' => '']) }}"
       class="btn btn-sm {{ $filters['review'] === 'todo' ? 'btn-warning' : 'btn-outline-warning' }}">
        <i class="fas fa-bell me-1"></i>Not checked <span class="badge bg-light text-dark ms-1">{{ $toReviewCount }}</span>
    </a>

    @foreach(\App\Models\ShopifyOrder::STATUSES as $status => $config)
        <a href="{{ $listUrl(['status' => $status, 'review' => '']) }}"
           class="btn btn-sm {{ $filters['status'] === $status ? 'btn-' . $config['color'] : 'btn-outline-' . $config['color'] }}">
            <i class="fas {{ $config['icon'] }} me-1"></i>{{ $config['label'] }}
            <span class="badge bg-light text-dark ms-1">{{ $statusCounts[$status] ?? 0 }}</span>
        </a>
    @endforeach
</div>

{{-- SEARCH --}}
<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('auto-subscriptions.index') }}" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="{{ $filters['status'] }}">
            <input type="hidden" name="review" value="{{ $filters['review'] }}">

            <div class="col-md-6">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control"
                       placeholder="Order #, phone, name, address...">
            </div>

            <div class="col-md-auto">
                <button class="btn btn-mauve"><i class="fas fa-search me-1"></i>Search</button>
                @if($filters['search'] !== '')
                    <a href="{{ $listUrl(['search' => '']) }}" class="btn btn-outline-secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>
</div>

{{-- LIST --}}
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Order</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Zone</th>
                    <th>Products</th>
                    <th class="text-end">Total</th>
                    <th>Status</th>
                    <th>Checked</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                    @php
                        $config = $order->statusConfig();
                        $errors = count($order->errors());
                        $warnings = count(array_filter($order->warnings(), fn ($i) => ($i['level'] ?? '') === 'warning'));
                    @endphp
                    <tr class="{{ !$order->reviewed_at && $order->status !== 'ignored' ? 'table-warning bg-opacity-25' : '' }}">
                        <td class="fw-bold text-nowrap">
                            <a href="{{ route('auto-subscriptions.show', $order) }}" class="text-decoration-none">
                                {{ $order->shopify_order_name ?: '#' . $order->shopify_order_id }}
                            </a>
                            @if($order->customer_created)
                                <span class="badge bg-info ms-1" title="New customer">NEW</span>
                            @endif
                        </td>
                        <td class="small text-nowrap">{{ $order->ordered_at?->format('d/m/Y H:i') ?? '-' }}</td>
                        <td>
                            <div>{{ $order->customerName() }}</div>
                            <div class="small text-muted">{{ $order->phone ? '+' . $order->phone : ($order->phone_raw ?: '-') }}</div>
                        </td>
                        <td class="small">
                            {{ $order->zone?->name ?? '-' }}
                            <div class="text-muted">{{ $order->province?->name ?? $order->province_raw }}</div>
                        </td>
                        <td class="small">
                            @foreach($order->line_items ?? [] as $line)
                                <div>
                                    @if(!empty($line['matched_product_id']))
                                        <i class="fas fa-link text-success me-1" title="Linked to {{ $line['matched_product_name'] }}"></i>
                                    @else
                                        <i class="fas fa-unlink text-danger me-1" title="Not linked"></i>
                                    @endif
                                    {{ \Illuminate\Support\Str::limit($line['title'] . (!empty($line['variant_title']) ? ' - ' . $line['variant_title'] : ''), 45) }}
                                    @if(($line['quantity'] ?? 1) > 1)
                                        <span class="badge bg-danger">x{{ $line['quantity'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </td>
                        <td class="text-end text-nowrap">{{ $order->total_price !== null ? number_format((float) $order->total_price, 3) . ' ' . $order->currency : '-' }}</td>
                        <td>
                            <span class="badge bg-{{ $config['color'] }}"><i class="fas {{ $config['icon'] }} me-1"></i>{{ $config['label'] }}</span>
                            @if($errors)
                                <span class="badge bg-danger">{{ $errors }} error{{ $errors > 1 ? 's' : '' }}</span>
                            @endif
                            @if($warnings)
                                <span class="badge bg-warning text-dark">{{ $warnings }} to check</span>
                            @endif
                        </td>
                        <td class="small">
                            @if($order->reviewed_at)
                                <i class="fas fa-check text-success"></i> {{ $order->reviewed_at->format('d/m H:i') }}
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('auto-subscriptions.show', $order) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">
                            <i class="fab fa-shopify fa-2x mb-2 d-block"></i>
                            No Shopify order yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($orders->hasPages())
        <div class="card-footer">{{ $orders->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
<script>
    // Refresh: button locked while Shopify is read (can take a few seconds)
    (function () {
        const form = document.getElementById('syncForm');
        const button = document.getElementById('syncButton');
        if (!form || !button) return;

        form.addEventListener('submit', function () {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-sync-alt fa-spin me-1"></i>Reading Shopify…';
        });
    })();
</script>
@endsection

@extends('layouts.app')

@section('title', 'Purchases')

@section('content')
@php
    $authenticatedUser = auth()->user();
    $canCreatePurchase = $authenticatedUser && $authenticatedUser->hasPermission('purchases.create');

    $hasFilters = collect($filters)->filter(fn ($value) => $value !== '')->isNotEmpty();
    $totalCount = array_sum($statusCounts);

    // Status chips keep the other filters
    $statusUrl = fn ($status) => route('purchases.index', array_merge(
        array_filter($filters, fn ($value) => $value !== ''),
        ['status' => $status]
    ));
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="text-mauve mb-0">Purchases</h2>

    @if($canCreatePurchase)
        <a href="{{ route('purchases.create') }}" class="btn btn-mauve">
            <i class="fas fa-plus me-2"></i>Add Purchase
        </a>
    @endif
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- PREMIUM-... packs to treat --}}
@if($premiumPendingCount > 0 && $filters['premium'] !== 'pending')
    <a href="{{ route('purchases.index', ['premium' => 'pending']) }}"
       class="alert alert-warning d-flex align-items-center gap-2 py-2 text-decoration-none">
        <i class="fas fa-crown text-warning"></i>
        <span><b>{{ $premiumPendingCount }}</b> premium purchase(s) untreated: contact the customer and assign his custom product.</span>
        <span class="ms-auto fw-semibold">View <i class="fas fa-arrow-right ms-1"></i></span>
    </a>
@endif

{{-- STATUS CHIPS --}}
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ $statusUrl('') }}"
       class="btn btn-sm {{ $filters['status'] === '' ? 'btn-mauve' : 'btn-outline-secondary' }}">
        All <span class="badge bg-light text-dark ms-1">{{ $totalCount }}</span>
    </a>

    @foreach(\App\Models\Purchase::STATUSES as $status => $config)
        <a href="{{ $statusUrl($status) }}"
           class="btn btn-sm {{ $filters['status'] === $status ? 'btn-' . $config['color'] : 'btn-outline-' . $config['color'] }}">
            {{ $config['label'] }}
            <span class="badge bg-light text-dark ms-1">{{ $statusCounts[$status] ?? 0 }}</span>
        </a>
    @endforeach

    <span class="vr mx-1"></span>

    <a href="{{ route('purchases.index', array_merge(array_filter($filters, fn ($v) => $v !== ''), ['premium' => $filters['premium'] === 'pending' ? '' : 'pending'])) }}"
       class="btn btn-sm {{ $filters['premium'] === 'pending' ? 'btn-warning' : 'btn-outline-warning' }}">
        <i class="fas fa-crown me-1"></i>Premium untreated
        <span class="badge bg-danger ms-1">{{ $premiumPendingCount }}</span>
    </a>

    <a href="{{ route('purchases.index', array_merge(array_filter($filters, fn ($v) => $v !== ''), ['premium' => $filters['premium'] === 'all' ? '' : 'all'])) }}"
       class="btn btn-sm {{ $filters['premium'] === 'all' ? 'btn-dark' : 'btn-outline-dark' }}">
        All premium
    </a>
</div>

{{-- FILTERS --}}
<div class="card shadow mb-3">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('purchases.index') }}" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="{{ $filters['status'] }}">
            <input type="hidden" name="premium" value="{{ $filters['premium'] }}">

            <div class="col-md-3">
                <label class="form-label small mb-1">Search</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control" value="{{ $filters['search'] }}"
                           placeholder="Ref, customer, phone, code...">
                </div>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Product</label>
                <select name="product_id" class="form-select">
                    <option value="">All</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}" @selected($filters['product_id'] === (string) $product->id)>
                            {{ $product->name }}{{ $product->deleted_at ? ' (deleted)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-1">
                <label class="form-label small mb-1">Source</label>
                <select name="source" class="form-select">
                    <option value="">All</option>
                    @foreach(\App\Models\Purchase::SOURCES as $value => $label)
                        <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Payment</label>
                <select name="payment_method" class="form-select">
                    <option value="">All</option>
                    @foreach(\App\Models\Purchase::PAYMENT_METHODS as $value => $label)
                        <option value="{{ $value }}" @selected($filters['payment_method'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-1">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}">
            </div>

            <div class="col-6 col-md-1">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}">
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-mauve flex-fill">
                    <i class="fas fa-filter me-1"></i>Filter
                </button>
                @if($hasFilters)
                    <a href="{{ route('purchases.index') }}" class="btn btn-outline-secondary" title="Reset">
                        <i class="fas fa-undo"></i>
                    </a>
                @endif
            </div>
        </form>
    </div>
</div>

{{-- LIST --}}
<div class="card shadow">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Pet</th>
                        <th>Product</th>
                        <th class="text-end">Total</th>
                        <th>Payment</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchases as $purchase)
                        <tr class="purchase-row {{ $purchase->premium_status === 'pending' ? 'premium-pending' : '' }}" onclick="window.location='{{ route('purchases.show', $purchase) }}'">
                            <td class="fw-semibold text-nowrap">{{ $purchase->reference }}</td>

                            <td class="text-nowrap small">
                                {{ $purchase->purchased_at?->format('d/m/Y') }}
                                <div class="text-muted">{{ $purchase->purchased_at?->format('H:i') }}</div>
                            </td>

                            <td>
                                <div>{{ $purchase->customer_name ?: '-' }}</div>
                                <div class="small text-muted">
                                    {{ $purchase->subscription?->code }}
                                    @if($purchase->customer_phone) · {{ $purchase->customer_phone }} @endif
                                </div>
                            </td>

                            <td>
                                <i class="fas fa-{{ $purchase->species === 'Dog' ? 'dog' : 'cat' }} text-mauve me-1"></i>
                                {{ $purchase->animal_name ?: '-' }}
                            </td>

                            <td>
                                <div>{{ $purchase->product_name }}</div>

                                {{-- PREMIUM-... pack --}}
                                @if($purchase->premium_status === 'pending')
                                    <span class="badge bg-warning text-dark"><i class="fas fa-crown me-1"></i>Premium</span>
                                    <span class="badge bg-danger">Untreated</span>
                                @elseif($purchase->premium_status === 'done')
                                    <span class="badge bg-warning text-dark"><i class="fas fa-crown me-1"></i>Premium</span>
                                    <span class="badge bg-success">Treated</span>
                                @endif

                                <div class="small text-muted">
                                    {{ $purchase->total_recipes }} recipes · {{ $purchase->durationLabel() }}
                                    @if($purchase->product_type === 'premium' && !$purchase->premium_status)
                                        <span class="badge bg-warning text-dark">Premium</span>
                                    @endif
                                    @if($purchase->is_trial)
                                        <span class="badge bg-info text-dark">Trial</span>
                                    @endif
                                </div>
                            </td>

                            <td class="text-end text-nowrap fw-semibold">
                                {{ number_format($purchase->total_amount, 3) }} {{ $purchase->currency }}
                                @if((float) $purchase->discount_amount > 0)
                                    <div class="small text-success">-{{ number_format($purchase->discount_amount, 3) }}</div>
                                @endif
                            </td>

                            <td class="small">
                                {{ $purchase->payment_method_label ?? '-' }}
                                @if($purchase->paid_at)
                                    <div class="text-muted">{{ $purchase->paid_at->format('d/m/Y') }}</div>
                                @endif
                            </td>

                            <td class="small">{{ $purchase->source_label }}</td>

                            <td>
                                <span class="badge bg-{{ \App\Models\Purchase::statusColor($purchase->status) }}">
                                    {{ \App\Models\Purchase::statusLabel($purchase->status) }}
                                </span>
                            </td>

                            <td class="text-end">
                                <i class="fas fa-chevron-right text-muted"></i>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                <i class="fas fa-info-circle me-1"></i>
                                {{ $hasFilters ? 'No purchases with current filters.' : 'No purchases yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            {{ $purchases->links('pagination::bootstrap-5') }}
        </div>
    </div>
</div>

<style>
.purchase-row { cursor: pointer; }
.purchase-row:hover { background-color: rgba(108, 92, 231, 0.06); }
.purchase-row.premium-pending > td { background-color: #fff8e5 !important; }
.purchase-row.premium-pending > td:first-child { box-shadow: inset 4px 0 0 #f0ad4e; }
</style>
@endsection

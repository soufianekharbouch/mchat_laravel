@extends('layouts.app')

@section('title', 'Test a Shopify order')

@section('content')
@php
    $levels = [
        'error' => ['class' => 'danger', 'icon' => 'fa-times-circle'],
        'warning' => ['class' => 'warning', 'icon' => 'fa-exclamation-triangle'],
        'info' => ['class' => 'light', 'icon' => 'fa-info-circle'],
    ];
@endphp

<div class="mb-3">
    <a href="{{ route('auto-subscriptions.index') }}" class="text-decoration-none small">
        <i class="fas fa-arrow-left me-1"></i>Auto subscription
    </a>
    <h2 class="text-mauve mb-0"><i class="fas fa-vial me-2"></i>Test a Shopify order</h2>
    <div class="text-muted small">Temporary page: run the import on an order already placed on Shopify.</div>
</div>

@if($errors->any())
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i>{{ $errors->first() }}</div>
@endif

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="POST" action="{{ route('auto-subscriptions.test.run') }}">
            @csrf

            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-bold">Shopify order</label>
                    <input type="text" name="order" value="{{ old('order') }}" class="form-control form-control-lg"
                           placeholder="#1001 or 6123456789012">
                    <div class="form-text">Order number (#1001) or order id (Admin URL: /orders/<b>6123456789012</b>).</div>
                </div>

                <div class="col-md-7">
                    <label class="form-label fw-bold">... or paste the JSON</label>
                    <textarea name="json" rows="4" class="form-control font-monospace small"
                              placeholder='{"id": 820982911946154508, "email": "50000000", "line_items": [...] }'>{{ old('json') }}</textarea>
                    <div class="form-text">Webhook test data or the .json of an order. Used instead of the order number when filled.</div>
                </div>
            </div>

            <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" name="force_paid" value="1" id="force_paid" @checked(old('force_paid', true))>
                <label class="form-check-label" for="force_paid">
                    Treat as <b>paid</b> (test orders are often "voided" / cancelled)
                </label>
            </div>

            <div class="d-flex gap-2 mt-3 flex-wrap">
                <button name="mode" value="simulate" class="btn btn-primary">
                    <i class="fas fa-flask me-1"></i>Simulate (nothing saved)
                </button>
                <button name="mode" value="import" class="btn btn-mauve"
                        onclick="return confirm('Really create the customer, purchase and deliveries?');">
                    <i class="fas fa-file-import me-1"></i>Import for real
                </button>
            </div>
        </form>
    </div>
</div>

@if($result)
    <div class="card shadow-sm mb-3 border-primary">
        <div class="card-header bg-primary text-white fw-bold">
            <i class="fas fa-flask me-1"></i>Simulation result: {{ $result['name'] }}
            <span class="badge bg-{{ $result['status_config']['color'] }} ms-2">{{ $result['status_config']['label'] }}</span>
            <span class="small fw-normal ms-2">(nothing was saved)</span>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3 small">
                <div class="col-md-4">
                    <div class="text-muted">Customer</div>
                    <div class="fw-bold">{{ $result['customer'] }}</div>
                    <div>
                        Phone: {{ $result['phone'] ? '+' . $result['phone'] : 'not found' }}
                        <span class="text-muted">(read: {{ $result['phone_raw'] ?: 'empty' }})</span>
                    </div>
                    <div>{{ $result['customer_created'] ? 'NEW customer' : 'Existing customer' }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted">Address</div>
                    <div>{{ $result['address'] ?: '-' }}</div>
                    <span class="badge bg-{{ $result['province'] ? 'success' : 'danger' }}">
                        Governorate: {{ $result['province'] ?? 'not found' }} ({{ $result['province_raw'] ?: '-' }})
                    </span>
                    <span class="badge bg-{{ $result['zone'] ? 'success' : 'danger' }}">Zone: {{ $result['zone'] ?? 'not found' }}</span>
                </div>
                <div class="col-md-4">
                    <div class="text-muted">Products</div>
                    @foreach($result['line_items'] as $line)
                        <div>
                            <i class="fas {{ !empty($line['matched_product_id']) ? 'fa-link text-success' : 'fa-unlink text-danger' }} me-1"></i>
                            {{ $line['title'] }} ×{{ $line['quantity'] }}
                            @if(!empty($line['matched_product_name']))
                                → <b>{{ $line['matched_product_name'] }}</b>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <h6 class="fw-bold">What would be done</h6>
            @foreach($result['issues'] as $issue)
                @php $level = $levels[$issue['level'] ?? 'info'] ?? $levels['info']; @endphp
                <div class="alert alert-{{ $level['class'] }} py-2 px-3 mb-2 small">
                    <i class="fas {{ $level['icon'] }} me-1"></i>{{ $issue['message'] ?? '' }}
                </div>
            @endforeach

            @foreach($result['purchases'] as $purchase)
                <div class="border rounded p-2 mt-3 small">
                    <div class="fw-bold">
                        {{ $purchase['reference'] }} · {{ $purchase['product'] }} · {{ $purchase['animal'] }}
                        <span class="badge bg-info">{{ $purchase['status'] }}</span> · {{ $purchase['total'] }}
                    </div>
                    @forelse($purchase['deliveries'] as $delivery)
                        <div><i class="fas fa-truck me-1 text-muted"></i>{{ $delivery['date'] }}: {{ $delivery['items'] }}</div>
                    @empty
                        <div class="text-muted">No delivery planned.</div>
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>
@endif
@endsection

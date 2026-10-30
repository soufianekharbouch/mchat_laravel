@extends('layouts.app')

@section('title', 'Edit Purchase')

@section('content')
@php
    $dateTimeValue = fn ($value) => $value ? \Carbon\Carbon::parse($value)->format('Y-m-d\TH:i') : '';
    $dateValue = fn ($value) => $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : '';
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="text-mauve mb-0">Edit Purchase {{ $purchase->reference }}</h2>
        <div class="text-muted small">
            {{ $purchase->customer_name }} · {{ $purchase->product_name }}
            · <span class="badge bg-{{ \App\Models\Purchase::statusColor($purchase->status) }}">
                {{ \App\Models\Purchase::statusLabel($purchase->status) }}
            </span>
        </div>
    </div>
    <a href="{{ route('purchases.show', $purchase) }}" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Back
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0 small">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="alert alert-light border small">
    <i class="fas fa-info-circle me-1"></i>
    The customer and the product cannot be changed. The status is changed from the purchase page.
</div>

<form method="POST" action="{{ route('purchases.update', $purchase) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')

    <div class="row g-3">
        <div class="col-lg-8">

            {{-- PET --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-yellow py-2"><strong><i class="fas fa-paw me-1"></i>Pet</strong></div>
                <div class="card-body">
                    <select name="animal_id" class="form-select @error('animal_id') is-invalid @enderror">
                        <option value="">— No specific pet —</option>
                        @foreach($animals as $animal)
                            <option value="{{ $animal->id }}" @selected((string) old('animal_id', $purchase->animal_id) === (string) $animal->id)>
                                {{ $animal->name }} ({{ $animal->species }}{{ $animal->breed_size_category ? ', ' . $animal->breed_size_category : '' }})
                            </option>
                        @endforeach
                    </select>
                    @error('animal_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">{{ $purchase->species }} pets of this customer.</div>
                </div>
            </div>

            {{-- PAYMENT --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-yellow py-2"><strong><i class="fas fa-credit-card me-1"></i>Payment</strong></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="payment_method" class="form-label">Payment method</label>
                            <select name="payment_method" id="payment_method" class="form-select @error('payment_method') is-invalid @enderror">
                                <option value="">— Not paid yet —</option>
                                @foreach(\App\Models\Purchase::PAYMENT_METHODS as $value => $label)
                                    <option value="{{ $value }}" @selected(old('payment_method', $purchase->payment_method) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4">
                            <label for="payment_reference" class="form-label">Payment reference</label>
                            <input type="text" name="payment_reference" id="payment_reference" maxlength="191"
                                   class="form-control" value="{{ old('payment_reference', $purchase->payment_reference) }}">
                        </div>

                        <div class="col-md-4">
                            <label for="paid_at" class="form-label">Paid at</label>
                            <input type="datetime-local" name="paid_at" id="paid_at"
                                   class="form-control @error('paid_at') is-invalid @enderror"
                                   value="{{ old('paid_at', $dateTimeValue($purchase->paid_at)) }}">
                        </div>

                        <div class="col-12">
                            <label for="payment_proof" class="form-label">Payment proof</label>

                            @if($purchase->payment_proof_url)
                                <div class="mb-2 small">
                                    <a href="{{ $purchase->payment_proof_url }}" target="_blank">
                                        <i class="fas fa-paperclip me-1"></i>Current file
                                    </a>
                                    <div class="form-check d-inline-block ms-3">
                                        <input type="hidden" name="remove_payment_proof" value="0">
                                        <input class="form-check-input" type="checkbox" id="remove_payment_proof" name="remove_payment_proof" value="1">
                                        <label class="form-check-label" for="remove_payment_proof">Remove</label>
                                    </div>
                                </div>
                            @endif

                            <input type="file" name="payment_proof" id="payment_proof" accept="image/*,application/pdf"
                                   class="form-control @error('payment_proof') is-invalid @enderror">
                            @error('payment_proof')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6">
                            <label for="shopify_order_name" class="form-label">Shopify order</label>
                            <input type="text" name="shopify_order_name" id="shopify_order_name" maxlength="100"
                                   class="form-control" value="{{ old('shopify_order_name', $purchase->shopify_order_name) }}">
                        </div>

                        <div class="col-md-6">
                            <label for="shopify_order_id" class="form-label">Shopify order ID</label>
                            <input type="text" name="shopify_order_id" id="shopify_order_id" maxlength="100"
                                   class="form-control" value="{{ old('shopify_order_id', $purchase->shopify_order_id) }}">
                        </div>
                    </div>
                </div>
            </div>

            {{-- NOTES --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-yellow py-2"><strong><i class="fas fa-sticky-note me-1"></i>Notes</strong></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="customer_note" class="form-label">Customer note</label>
                            <textarea name="customer_note" id="customer_note" rows="3" class="form-control">{{ old('customer_note', $purchase->customer_note) }}</textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="admin_note" class="form-label">Internal note</label>
                            <textarea name="admin_note" id="admin_note" rows="3" class="form-control">{{ old('admin_note', $purchase->admin_note) }}</textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">

            {{-- PRICE --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-yellow py-2"><strong><i class="fas fa-coins me-1"></i>Price ({{ $purchase->currency }})</strong></div>
                <div class="card-body">
                    <label for="unit_price" class="form-label">Price *</label>
                    <input type="number" step="0.001" min="0" name="unit_price" id="unit_price"
                           class="form-control mb-2 @error('unit_price') is-invalid @enderror"
                           value="{{ old('unit_price', $purchase->unit_price) }}" required>

                    <label for="discount_amount" class="form-label">Discount</label>
                    <input type="number" step="0.001" min="0" name="discount_amount" id="discount_amount"
                           class="form-control @error('discount_amount') is-invalid @enderror"
                           value="{{ old('discount_amount', $purchase->discount_amount) }}">
                    @error('discount_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror

                    <div class="d-flex justify-content-between align-items-center mt-3 p-2 rounded" style="background:#fff8e1;">
                        <span>Total</span>
                        <strong id="totalAmount"></strong>
                    </div>
                </div>
            </div>

            {{-- DATES --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-yellow py-2"><strong><i class="fas fa-calendar me-1"></i>Dates</strong></div>
                <div class="card-body">
                    <label for="purchased_at" class="form-label">Purchase date *</label>
                    <input type="datetime-local" name="purchased_at" id="purchased_at"
                           class="form-control mb-3 @error('purchased_at') is-invalid @enderror"
                           value="{{ old('purchased_at', $dateTimeValue($purchase->purchased_at)) }}" required>

                    <label for="starts_on" class="form-label">Pack start</label>
                    <input type="date" name="starts_on" id="starts_on"
                           class="form-control mb-3 @error('starts_on') is-invalid @enderror"
                           value="{{ old('starts_on', $dateValue($purchase->starts_on)) }}">

                    <label for="ends_on" class="form-label">Pack end</label>
                    <input type="date" name="ends_on" id="ends_on"
                           class="form-control @error('ends_on') is-invalid @enderror"
                           value="{{ old('ends_on', $dateValue($purchase->ends_on)) }}">
                    @error('ends_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Empty end = computed from the start ({{ $purchase->durationLabel() }}).</div>
                </div>
            </div>

            <button type="submit" class="btn btn-mauve w-100">
                <i class="fas fa-save me-1"></i>Save changes
            </button>
        </div>
    </div>
</form> 

<script>
document.addEventListener('DOMContentLoaded', function () {
    var price = document.getElementById('unit_price');
    var discount = document.getElementById('discount_amount');
    var total = document.getElementById('totalAmount');

    function updateTotal() {
        var value = Math.max(0, (parseFloat(price.value) || 0) - (parseFloat(discount.value) || 0));
        total.textContent = value.toFixed(3) + ' {{ $purchase->currency }}';
    }

    price.addEventListener('input', updateTotal);
    discount.addEventListener('input', updateTotal);
    updateTotal();
});
</script>
@endsection

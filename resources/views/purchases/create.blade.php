@extends('layouts.app')

@section('title', 'Add Purchase')

@section('content')
@php
    $productsJs = $products->map(fn ($product) => [
        'id' => $product->id,
        'code' => $product->code,
        'name' => $product->name,
        'species' => $product->species,
        'breed_size_category' => $product->breed_size_category,
        'product_type' => $product->product_type,
        'is_trial' => (bool) $product->is_trial,
        'is_active' => (bool) $product->is_active,
        'price' => (float) $product->price,
        'current_price' => $product->currentPrice(),
        'currency' => $product->currency,
        'duration' => $product->durationLabel(),
        'total_recipes' => $product->total_recipes,
        'deliveries' => $product->min_deliveries === $product->max_deliveries
            ? (string) $product->min_deliveries
            : $product->min_deliveries . '–' . $product->max_deliveries,
        'per_delivery' => $product->min_recipes_per_delivery === $product->max_recipes_per_delivery
            ? (string) $product->min_recipes_per_delivery
            : $product->min_recipes_per_delivery . '–' . $product->max_recipes_per_delivery,
        'max_purchases_per_customer' => $product->max_purchases_per_customer,
    ])->values();

    $selectedSubscriptionId = old('subscription_id', $preselectedSubscriptionId);
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="text-mauve mb-0">Add Purchase</h2>
    <a href="{{ route('purchases.index') }}" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Back
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1"><i class="fas fa-exclamation-triangle me-1"></i>Please fix the following:</div>
        <ul class="mb-0 small">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('purchases.store') }}" enctype="multipart/form-data" id="purchaseForm">
    @csrf

    <div class="row g-3">
        <div class="col-lg-8">

            {{-- CUSTOMER & PET --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-yellow py-2"><strong><i class="fas fa-user me-1"></i>Customer &amp; pet</strong></div>
                <div class="card-body">
                    <label class="form-label">Customer *</label>
                    <input type="text" class="form-control mb-2" id="customerSearch"
                           placeholder="Search by name, phone or subscription code..." autocomplete="off">

                    <select name="subscription_id" id="subscription_id" size="6"
                            class="form-select customer-select @error('subscription_id') is-invalid @enderror" required>
                    </select>
                    @error('subscription_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text" id="customerHint"></div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label for="animal_id" class="form-label">Pet</label>
                            <select name="animal_id" id="animal_id" class="form-select @error('animal_id') is-invalid @enderror">
                                <option value="">— Select a customer first —</option>
                            </select>
                            @error('animal_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">The product list is filtered on the pet species.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="product_id" class="form-label">Product *</label>
                            <select name="product_id" id="product_id" class="form-select @error('product_id') is-invalid @enderror" required>
                            </select>
                            @error('product_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div id="productSummary" class="product-summary mt-3 d-none"></div>
                </div>
            </div>

            {{-- PAYMENT --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-yellow py-2"><strong><i class="fas fa-credit-card me-1"></i>Payment</strong></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="source" class="form-label">Source *</label>
                            <select name="source" id="source" class="form-select" required>
                                @foreach(\App\Models\Purchase::SOURCES as $value => $label)
                                    <option value="{{ $value }}" @selected(old('source', 'admin') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="payment_method" class="form-label">Payment method</label>
                            <select name="payment_method" id="payment_method"
                                    class="form-select @error('payment_method') is-invalid @enderror">
                                <option value="">— Not paid yet —</option>
                                @foreach(\App\Models\Purchase::PAYMENT_METHODS as $value => $label)
                                    <option value="{{ $value }}" @selected(old('payment_method') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4">
                            <label for="payment_reference" class="form-label">Payment reference</label>
                            <input type="text" name="payment_reference" id="payment_reference" maxlength="191"
                                   class="form-control" value="{{ old('payment_reference') }}"
                                   placeholder="Transaction / transfer n°">
                        </div>

                        <div class="col-md-4">
                            <label for="paid_at" class="form-label">Paid at</label>
                            <input type="datetime-local" name="paid_at" id="paid_at"
                                   class="form-control @error('paid_at') is-invalid @enderror" value="{{ old('paid_at') }}">
                            <div class="form-text">Empty = now, when marked as paid.</div>
                        </div>

                        <div class="col-md-8">
                            <label for="payment_proof" class="form-label">Payment proof (transfer receipt)</label>
                            <input type="file" name="payment_proof" id="payment_proof" accept="image/*,application/pdf"
                                   class="form-control @error('payment_proof') is-invalid @enderror">
                            @error('payment_proof')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6 shopify-fields">
                            <label for="shopify_order_name" class="form-label">Shopify order</label>
                            <input type="text" name="shopify_order_name" id="shopify_order_name" maxlength="100"
                                   class="form-control" value="{{ old('shopify_order_name') }}" placeholder="#1052">
                        </div>

                        <div class="col-md-6 shopify-fields">
                            <label for="shopify_order_id" class="form-label">Shopify order ID</label>
                            <input type="text" name="shopify_order_id" id="shopify_order_id" maxlength="100"
                                   class="form-control" value="{{ old('shopify_order_id') }}">
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
                            <textarea name="customer_note" id="customer_note" rows="3" class="form-control">{{ old('customer_note') }}</textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="admin_note" class="form-label">Internal note</label>
                            <textarea name="admin_note" id="admin_note" rows="3" class="form-control">{{ old('admin_note') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">

            {{-- PRICE --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-yellow py-2"><strong><i class="fas fa-coins me-1"></i>Price</strong></div>
                <div class="card-body">
                    <label for="unit_price" class="form-label">Price *</label>
                    <input type="number" step="0.001" min="0" name="unit_price" id="unit_price"
                           class="form-control mb-2 @error('unit_price') is-invalid @enderror"
                           value="{{ old('unit_price') }}" required>
                    @error('unit_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text mb-2">Filled with the current product price (promo included).</div>

                    <label for="discount_amount" class="form-label">Discount</label>
                    <input type="number" step="0.001" min="0" name="discount_amount" id="discount_amount"
                           class="form-control @error('discount_amount') is-invalid @enderror"
                           value="{{ old('discount_amount', 0) }}">
                    @error('discount_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror

                    <div class="total-box mt-3">
                        <span>Total</span>
                        <strong id="totalAmount">0.000</strong>
                    </div>
                </div>
            </div>

            {{-- STATUS & DATES --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-yellow py-2"><strong><i class="fas fa-calendar me-1"></i>Status &amp; dates</strong></div>
                <div class="card-body">
                    <label for="initial_status" class="form-label">Status *</label>
                    <select name="initial_status" id="initial_status" class="form-select mb-1" required>
                        <option value="pending" @selected(old('initial_status', 'pending') === 'pending')>Pending payment</option>
                        <option value="paid" @selected(old('initial_status') === 'paid')>Paid (customer plans the deliveries)</option>
                        <option value="active" @selected(old('initial_status') === 'active')>Active (pack period starts)</option>
                    </select>
                    <div class="form-text mb-3">Paid and Active need a payment method.</div>

                    <label for="purchased_at" class="form-label">Purchase date *</label>
                    <input type="datetime-local" name="purchased_at" id="purchased_at"
                           class="form-control mb-3 @error('purchased_at') is-invalid @enderror"
                           value="{{ old('purchased_at', now()->format('Y-m-d\TH:i')) }}" required>

                    <label for="starts_on" class="form-label">Pack start date</label>
                    <input type="date" name="starts_on" id="starts_on"
                           class="form-control @error('starts_on') is-invalid @enderror" value="{{ old('starts_on') }}">
                    <div class="form-text" id="endDateHint">Empty = the day the purchase becomes Active.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-mauve w-100">
                <i class="fas fa-save me-1"></i>Create purchase
            </button>
        </div>
    </div>
</form>

<style>
.customer-select option { padding: 6px 8px; }
.product-summary {
    border-radius: 10px;
    padding: 10px 14px;
    background: rgba(108, 92, 231, 0.06);
    border: 1px solid rgba(108, 92, 231, 0.15);
    font-size: .9rem;
}
.total-box {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    border-radius: 10px;
    background: #fff8e1;
    font-size: 1.1rem;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var customers = @json($customers);
    var products = @json($productsJs);

    var selectedSubscriptionId = @json((string) $selectedSubscriptionId);
    var selectedAnimalId = @json((string) old('animal_id', ''));
    var selectedProductId = @json((string) old('product_id', ''));
    var hasOldPrice = @json(old('unit_price') !== null);

    var $ = function (id) { return document.getElementById(id); };

    var customerSearch = $('customerSearch');
    var customerSelect = $('subscription_id');
    var animalSelect = $('animal_id');
    var productSelect = $('product_id');

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function currentCustomer() {
        var id = customerSelect.value;
        return customers.find(function (c) { return String(c.id) === String(id); }) || null;
    }

    function currentAnimal() {
        var customer = currentCustomer();
        if (!customer) return null;
        return customer.animals.find(function (a) { return String(a.id) === String(animalSelect.value); }) || null;
    }

    function currentProduct() {
        return products.find(function (p) { return String(p.id) === String(productSelect.value); }) || null;
    }

    /*
     * Customers: filtered list (max 100 shown).
     */
    function renderCustomers() {
        var q = (customerSearch.value || '').trim().toLowerCase();
        var keep = customerSelect.value || selectedSubscriptionId;

        var matches = customers.filter(function (c) {
            if (String(c.id) === String(keep)) return true;
            if (!q) return true;
            return (c.name + ' ' + c.phone + ' ' + c.code).toLowerCase().indexOf(q) !== -1;
        }).slice(0, 100);

        customerSelect.innerHTML = matches.map(function (c) {
            return '<option value="' + c.id + '"' + (String(c.id) === String(keep) ? ' selected' : '') + '>'
                + esc(c.code + ' — ' + c.name + (c.phone ? ' · ' + c.phone : '')
                + (c.animals.length ? ' (' + c.animals.map(function (a) { return a.name; }).join(', ') + ')' : ''))
                + '</option>';
        }).join('');

        $('customerHint').textContent = matches.length >= 100
            ? 'First 100 results, refine the search.'
            : matches.length + ' customer(s).';
    }

    /*
     * Pets of the selected customer.
     */
    function renderAnimals() {
        var customer = currentCustomer();

        if (!customer) {
            animalSelect.innerHTML = '<option value="">— Select a customer first —</option>';
            return;
        }

        var options = ['<option value="">— No specific pet —</option>'];

        customer.animals.forEach(function (a) {
            var label = a.name + ' (' + a.species + (a.breed_size_category ? ', ' + a.breed_size_category : '') + ')';
            options.push('<option value="' + a.id + '"' + (String(a.id) === String(selectedAnimalId) ? ' selected' : '') + '>'
                + esc(label) + '</option>');
        });

        animalSelect.innerHTML = options.join('');

        // Only one pet: pre-selected
        if (!selectedAnimalId && customer.animals.length === 1) {
            animalSelect.value = String(customer.animals[0].id);
        }

        var hint = customer.has_account ? '' : ' — no app account yet (cannot plan deliveries in the app).';
        $('customerHint').textContent = customer.code + ' · ' + customer.name + hint;
    }

    /*
     * Products of the pet species (all products when no pet).
     */
    function renderProducts() {
        var animal = currentAnimal();
        var keep = productSelect.value || selectedProductId;

        var list = products.filter(function (p) {
            if (!animal) return true;
            if (p.species !== animal.species) return false;
            if (p.species === 'Dog' && p.breed_size_category && animal.breed_size_category
                && p.breed_size_category !== animal.breed_size_category) return false;
            return true;
        });

        productSelect.innerHTML = '<option value="">— Select a product —</option>' + list.map(function (p) {
            var label = p.name + ' · ' + p.current_price.toFixed(3) + ' ' + p.currency
                + (p.is_trial ? ' · Trial' : '') + (p.product_type === 'premium' ? ' · Premium' : '')
                + (p.is_active ? '' : ' (inactive)');
            return '<option value="' + p.id + '"' + (String(p.id) === String(keep) ? ' selected' : '') + '>'
                + esc(label) + '</option>';
        }).join('');

        renderProductSummary(false);
    }

    function renderProductSummary(updatePrice) {
        var product = currentProduct();
        var box = $('productSummary');

        if (!product) {
            box.classList.add('d-none');
            return;
        }

        box.classList.remove('d-none');
        box.innerHTML =
            '<div class="fw-semibold mb-1">' + esc(product.name) + ' <span class="text-muted small">(' + esc(product.code) + ')</span></div>'
            + '<div><strong>' + product.total_recipes + '</strong> recipes · ' + esc(product.duration)
            + ' · ' + esc(product.deliveries) + ' deliveries of ' + esc(product.per_delivery) + ' recipes</div>'
            + (product.current_price < product.price
                ? '<div class="text-success small">Promo: ' + product.current_price.toFixed(3) + ' instead of ' + product.price.toFixed(3) + '</div>'
                : '')
            + (product.max_purchases_per_customer
                ? '<div class="text-muted small">Limit: ' + product.max_purchases_per_customer + ' purchase(s) per customer</div>'
                : '');

        if (updatePrice) {
            $('unit_price').value = product.current_price.toFixed(3);
            updateTotal();
        }
    }

    function updateTotal() {
        var price = parseFloat($('unit_price').value) || 0;
        var discount = parseFloat($('discount_amount').value) || 0;
        var product = currentProduct();
        $('totalAmount').textContent = Math.max(0, price - discount).toFixed(3) + (product ? ' ' + product.currency : '');
    }

    function toggleShopifyFields() {
        var show = $('source').value === 'shopify' || $('payment_method').value === 'shopify';
        document.querySelectorAll('.shopify-fields').forEach(function (el) {
            el.style.display = show ? '' : 'none';
        });
    } 

    customerSearch.addEventListener('input', renderCustomers);

    customerSelect.addEventListener('change', function () {
        selectedAnimalId = '';
        renderAnimals();
        renderProducts();
    });

    animalSelect.addEventListener('change', renderProducts);

    productSelect.addEventListener('change', function () {
        renderProductSummary(true);
    });

    $('unit_price').addEventListener('input', updateTotal);
    $('discount_amount').addEventListener('input', updateTotal);
    $('source').addEventListener('change', toggleShopifyFields);
    $('payment_method').addEventListener('change', toggleShopifyFields);

    // Init
    renderCustomers();
    renderAnimals();
    renderProducts();
    renderProductSummary(!hasOldPrice);
    updateTotal();
    toggleShopifyFields();
});
</script>
@endsection

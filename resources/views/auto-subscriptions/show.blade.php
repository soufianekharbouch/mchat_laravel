@extends('layouts.app')

@section('title', 'Shopify order ' . ($order->shopify_order_name ?? ''))

@section('content')
@php
    $config = $order->statusConfig();
    $user = auth()->user();
    $canUpdate = $user && $user->hasPermission('auto_subscriptions.manage');
    $levels = [
        'error' => ['class' => 'danger', 'icon' => 'fa-times-circle'],
        'warning' => ['class' => 'warning', 'icon' => 'fa-exclamation-triangle'],
        'info' => ['class' => 'light', 'icon' => 'fa-info-circle'],
    ];
    $payload = $order->payloadArray();
    $shipping = $payload['shipping_address'] ?? [];
    $customer = $order->customerAccount;
    $addresses = $customer?->addresses ?? collect();
@endphp

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <a href="{{ route('auto-subscriptions.index') }}" class="text-decoration-none small">
            <i class="fas fa-arrow-left me-1"></i>Auto subscription
        </a>
        <h2 class="text-mauve mb-1">
            <i class="fab fa-shopify me-1"></i>{{ $order->shopify_order_name ?: '#' . $order->shopify_order_id }}
            <span class="badge bg-{{ $config['color'] }} fs-6 align-middle"><i class="fas {{ $config['icon'] }} me-1"></i>{{ $config['label'] }}</span>
        </h2>
        <div class="text-muted small">
            Ordered {{ $order->ordered_at?->format('D d/m/Y H:i') ?? '-' }}
            · Payment: {{ $order->financial_status ?? '-' }}
            · Received {{ $order->created_at?->format('d/m/Y H:i') }}
            · Attempts: {{ $order->attempts }}
        </div>
    </div>

    @if($canUpdate)
        <div class="d-flex gap-2 flex-wrap">
            <form method="POST" action="{{ route('auto-subscriptions.reprocess', $order) }}"
                  onsubmit="return confirm('{{ $order->status === 'ignored' ? 'This order is ignored: process it again and create its purchases and deliveries?' : 'Process this order again? Purchases already created are kept.' }}');">
                @csrf
                <button class="btn btn-outline-primary"><i class="fas fa-redo me-1"></i>Process again</button>
            </form>

            @if($order->status !== 'ignored')
                <form method="POST" action="{{ route('auto-subscriptions.ignore', $order) }}"
                      onsubmit="return confirm('Ignore this order? Nothing is kept for it: the purchases and deliveries already created for this order are removed.');">
                    @csrf
                    <button class="btn btn-outline-dark"><i class="fas fa-ban me-1"></i>Ignore</button>
                </form>
            @endif
        </div>
    @endif
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle me-1"></i>{{ $errors->first() }}
    </div>
@endif

<div class="row g-3 mb-3">
    {{-- RESULT --}}
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold">
                <i class="fas fa-clipboard-check me-1 text-mauve"></i>What was done
            </div>
            <div class="card-body">
                @forelse($order->issues ?? [] as $issue)
                    @php $level = $levels[$issue['level'] ?? 'info'] ?? $levels['info']; @endphp
                    <div class="alert alert-{{ $level['class'] }} py-2 px-3 mb-2 small">
                        <i class="fas {{ $level['icon'] }} me-1"></i>{{ $issue['message'] ?? '' }}
                    </div>
                @empty
                    <div class="text-muted small">Not processed yet.</div>
                @endforelse

                @if($canUpdate && $order->status !== 'ignored')
                    <form method="POST" action="{{ route('auto-subscriptions.review', $order) }}" class="mt-3 border-top pt-3">
                        @csrf
                        <label class="form-label small mb-1">Staff note</label>
                        <textarea name="admin_note" rows="2" class="form-control mb-2">{{ old('admin_note', $order->admin_note) }}</textarea>

                        @if($order->reviewed_at)
                            <div class="small text-success mb-2">
                                <i class="fas fa-check me-1"></i>Checked {{ $order->reviewed_at->format('d/m/Y H:i') }}
                                {{ $order->reviewedBy ? 'by ' . $order->reviewedBy->name : '' }}
                            </div>
                            <button class="btn btn-sm btn-outline-success"><i class="fas fa-save me-1"></i>Save note</button>
                        @else
                            <button class="btn btn-success"><i class="fas fa-check me-1"></i>Mark as checked</button>
                        @endif
                    </form>
                @endif
            </div>
        </div>
    </div>

    {{-- CUSTOMER + ADDRESS --}}
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-user me-1 text-mauve"></i>Customer
                    @if($order->customer_created)
                        <span class="badge bg-info ms-1">New</span>
                    @endif
                </span>
                @if($order->subscription)
                    <a href="{{ route('subscriptions.show', $order->subscription) }}" class="small fw-normal">
                        <i class="fas fa-receipt me-1"></i>{{ $order->subscription->code }}
                    </a>
                @endif
            </div>
            <div class="card-body small">
                {{-- What Shopify sent (reference) --}}
                <div class="bg-light rounded p-2 mb-3">
                    <div class="text-muted mb-1"><i class="fab fa-shopify me-1"></i>Sent by Shopify</div>
                    <div class="fw-bold">{{ trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? '')) ?: $order->customerName() }}</div>
                    <div>
                        <i class="fas fa-phone me-1 text-muted"></i>{{ $order->phone_raw ?: '-' }}
                    </div>
                    <div><i class="fas fa-map-marker-alt me-1 text-muted"></i>{{ $order->address_raw ?: '-' }}</div>
                    <div class="text-muted">{{ $order->province_raw }}</div>
                    <div class="mt-1">
                        <span class="badge bg-{{ $order->province_id ? 'success' : 'danger' }}">
                            Governorate: {{ $order->province?->name ?? 'not found' }}
                        </span>
                        <span class="badge bg-{{ $order->zone_id ? 'success' : 'danger' }}">
                            Zone: {{ $order->zone?->name ?? 'not found' }}
                        </span>
                    </div>
                    @if(!empty($payload['note']))
                        <div class="mt-2"><span class="text-muted">Order note:</span> {{ $payload['note'] }}</div>
                    @endif
                </div>

                @if(!$customer)
                    <div class="alert alert-warning py-2 mb-0">
                        No customer created yet: fix the phone / link the products, then "Process again".
                    </div>
                @elseif(!$canUpdate)
                    <div class="fw-bold fs-6">{{ $order->customerName() }}</div>
                    <div><i class="fas fa-phone me-1 text-muted"></i>+{{ $customer->phone }}</div>
                    <div><i class="fas fa-map-marker-alt me-1 text-muted"></i>{{ $currentAddress?->address ?? '-' }}</div>
                @else
                    @php
                        $subscriptionRow = $customer->subscription;
                        $addressProvince = old('province_id', $currentAddress?->province_id ?? $order->province_id);
                        $addressZone = old('zone_id', $currentAddress?->zone_id ?? $order->zone_id);
                    @endphp

                    {{-- Customer in our system: corrected here, then validated --}}
                    <form method="POST" action="{{ route('auto-subscriptions.customer', $order) }}" id="customerForm">
                        @csrf
                        <input type="hidden" name="address_id" value="{{ $currentAddress?->id }}">

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small mb-0">First name</label>
                                <input name="first_name" class="form-control form-control-sm" required
                                       value="{{ old('first_name', $subscriptionRow?->subscriber_first_name ?? $order->first_name) }}">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-0">Last name</label>
                                <input name="last_name" class="form-control form-control-sm"
                                       value="{{ old('last_name', $subscriptionRow?->subscriber_last_name ?? $order->last_name) }}">
                            </div>

                            <div class="col-12">
                                <label class="form-label small mb-0">Phone (WhatsApp, app sign in)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">+965</span>
                                    <input name="phone" class="form-control" required inputmode="numeric" maxlength="20"
                                           value="{{ old('phone', \App\Support\KuwaitPhone::local($customer->phone) ?? $customer->phone) }}">
                                </div>
                            </div>

                            <div class="col-6">
                                <label class="form-label small mb-0">Governorate</label>
                                <select name="province_id" id="provinceSelect" class="form-select form-select-sm" required>
                                    <option value="">Choose…</option>
                                    @foreach($provinces as $province)
                                        <option value="{{ $province->id }}" @selected((int) $addressProvince === (int) $province->id)>
                                            {{ $province->name }}{{ $province->name_ar ? ' - ' . $province->name_ar : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-0">Zone</label>
                                <select name="zone_id" id="zoneSelect" class="form-select form-select-sm" required>
                                    <option value="">Choose…</option>
                                    @foreach($provinces as $province)
                                        @foreach($province->zones as $zone)
                                            <option value="{{ $zone->id }}" data-province="{{ $province->id }}" @selected((int) $addressZone === (int) $zone->id)>
                                                {{ $zone->name }}{{ $zone->name_ar ? ' - ' . $zone->name_ar : '' }}
                                            </option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label small mb-0">Address (block, street, house, floor…)</label>
                                <textarea name="address" rows="2" class="form-control form-control-sm" required>{{ old('address', $currentAddress?->address ?? $order->address_raw) }}</textarea>
                            </div>

                            <div class="col-6">
                                <label class="form-label small mb-0">Delivery slot</label>
                                <select name="delivery_slot" class="form-select form-select-sm">
                                    <option value="">-</option>
                                    @foreach($slots as $slot)
                                        <option value="{{ $slot }}" @selected(old('delivery_slot', $currentAddress?->delivery_slot) === $slot)>{{ $slot }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-0">Driver note</label>
                                <input name="address_note" class="form-control form-control-sm" value="{{ old('address_note', $currentAddress?->note) }}">
                            </div>

                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="main_address" value="1" id="mainAddress"
                                           @checked(old('main_address', $order->customer_created || !trim((string) $subscriptionRow?->subscriber_address)))>
                                    <label class="form-check-label" for="mainAddress">Also the main address of the subscription</label>
                                </div>
                                <div class="text-muted">
                                    The deliveries of this order not prepared yet use this address.
                                    @if($currentAddress)
                                        Address edited: "{{ $currentAddress->label ?: 'Address' }}".
                                    @else
                                        A new address is created.
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap mt-3">
                            <button class="btn btn-sm btn-outline-primary"><i class="fas fa-save me-1"></i>Save</button>
                            @if($order->status !== 'ignored')
                                <button class="btn btn-sm btn-success" name="validate_order" value="1">
                                    <i class="fas fa-check-double me-1"></i>Save and validate
                                </button>
                            @endif
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- SHOPIFY PRODUCTS --}}
<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-bold">
        <i class="fas fa-shopping-bag me-1 text-mauve"></i>Shopify products
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Product / variant</th>
                    <th>Shopify ids</th>
                    <th class="text-center">Qty</th>
                    <th class="text-end">Price</th>
                    <th>Laravel product</th>
                </tr>
            </thead>
            <tbody>
                @forelse($order->line_items ?? [] as $line)
                    <tr>
                        <td>
                            {{ $line['title'] }}
                            @if(!empty($line['variant_title']))
                                <div class="small text-muted">{{ $line['variant_title'] }}</div>
                            @endif
                        </td>
                        <td class="small text-muted">P {{ $line['product_id'] ?? '-' }}<br>V {{ $line['variant_id'] ?? '-' }}</td>
                        <td class="text-center">
                            <span class="badge bg-{{ ($line['quantity'] ?? 1) > 1 ? 'danger' : 'secondary' }}">{{ $line['quantity'] ?? 1 }}</span>
                        </td>
                        <td class="text-end text-nowrap">
                            {{ number_format((float) ($line['price'] ?? 0), 3) }}
                            @if(!empty($line['discount']))
                                <div class="small text-danger">-{{ number_format((float) $line['discount'], 3) }}</div>
                            @endif
                        </td>
                        <td>
                            @if(!empty($line['matched_product_id']))
                                <a href="{{ route('products.edit', $line['matched_product_id']) }}" class="text-success">
                                    <i class="fas fa-link me-1"></i>{{ $line['matched_product_name'] }}
                                </a>
                            @else
                                <span class="text-danger"><i class="fas fa-unlink me-1"></i>Not linked</span>
                                <div class="small text-muted">Link this variant in Products, then "Process again".</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted text-center py-3">Products not read yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- PETS OF THE CUSTOMER --}}
@if($customer)
    @php
        $orderSpecies = $purchases->pluck('species')->filter()->first() ?? 'Cat';
        $packsOfOrder = $purchases->groupBy('animal_id');
    @endphp

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-bold"><i class="fas fa-paw me-1 text-mauve"></i>Pets of the customer ({{ $pets->count() }})</span>
            @if($canUpdate)
                <button class="btn btn-sm btn-mauve" type="button" data-bs-toggle="collapse" data-bs-target="#newPet">
                    <i class="fas fa-plus me-1"></i>Add a pet
                </button>
            @endif
        </div>

        {{-- New pet + packs given to it --}}
        @if($canUpdate)
            <div class="collapse {{ $errors->has('name') || old('purchase_ids') ? 'show' : '' }}" id="newPet">
                <form method="POST" action="{{ route('auto-subscriptions.pets.store', $order) }}" class="card-body border-bottom bg-light pet-form">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small mb-0">Name</label>
                            <input name="name" class="form-control form-control-sm" required value="{{ old('name') }}" placeholder="Luna">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-0">Species</label>
                            <select name="species" class="form-select form-select-sm species-select">
                                @foreach(array_keys($lifeStages) as $species)
                                    <option value="{{ $species }}" @selected(old('species', $orderSpecies) === $species)>{{ $species }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-0">Age</label>
                            <select name="age" class="form-select form-select-sm age-select">
                                <option value="">-</option>
                                @foreach($lifeStages as $species => $stages)
                                    @foreach($stages as $stage)
                                        <option value="{{ $stage }}" data-species="{{ $species }}" @selected(old('age') === $stage)>{{ $stage }}</option>
                                    @endforeach
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-0">Sex</label>
                            <select name="sex" class="form-select form-select-sm">
                                <option value="">-</option>
                                <option value="Male" @selected(old('sex') === 'Male')>Male</option>
                                <option value="Female" @selected(old('sex') === 'Female')>Female</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label small mb-0">Kg</label>
                            <input name="weight_kg" type="number" step="0.1" min="0" class="form-control form-control-sm" value="{{ old('weight_kg') }}">
                        </div>
                        <div class="col-md-2 dog-only">
                            <label class="form-label small mb-0">Size (dog)</label>
                            <select name="breed_size_category" class="form-select form-select-sm">
                                <option value="">-</option>
                                @foreach(\App\Models\Product::BREED_SIZES as $size)
                                    <option value="{{ $size }}" @selected(old('breed_size_category') === $size)>{{ $size }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    @if($purchases->isNotEmpty())
                        <div class="mt-3">
                            <div class="small fw-bold mb-1">Give these packs of the order to the new pet (dates planned again):</div>
                            @foreach($purchases as $purchase)
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="purchase_ids[]" value="{{ $purchase->id }}"
                                           id="givePack{{ $purchase->id }}" @checked(in_array($purchase->id, old('purchase_ids', [])))>
                                    <label class="form-check-label small" for="givePack{{ $purchase->id }}">
                                        {{ $purchase->reference }} · {{ \Illuminate\Support\Str::limit($purchase->product_name, 30) }}
                                        <span class="text-muted">(now: {{ $purchase->animal_name ?: '-' }})</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-3">
                        <button class="btn btn-sm btn-mauve"><i class="fas fa-save me-1"></i>Add the pet</button>
                    </div>
                </form>
            </div>
        @endif

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="min-width:150px">Name</th>
                        <th>Species</th>
                        <th>Age</th>
                        <th>Sex</th>
                        <th style="width:90px">Kg</th>
                        <th>Packs of this order</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pets as $pet)
                        @php
                            $ofOrder = $packsOfOrder->get($pet->id, collect());
                            $canRemove = !($packsByPet[$pet->id] ?? 0) && !$pet->planned_orders_count;
                            $formId = 'petForm' . $pet->id;
                        @endphp
                        <tr>
                            @if($canUpdate)
                                <td>
                                    <form method="POST" action="{{ route('auto-subscriptions.pets.update', [$order, $pet]) }}" id="{{ $formId }}" class="pet-form">
                                        @csrf
                                    </form>
                                    <input name="name" form="{{ $formId }}" class="form-control form-control-sm" value="{{ $pet->name }}" required>
                                </td>
                                <td>
                                    <select name="species" form="{{ $formId }}" class="form-select form-select-sm species-select" data-form="{{ $formId }}">
                                        @foreach(array_keys($lifeStages) as $species)
                                            <option value="{{ $species }}" @selected($pet->species === $species)>{{ $species }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="age" form="{{ $formId }}" class="form-select form-select-sm age-select" data-form="{{ $formId }}">
                                        <option value="">-</option>
                                        @foreach($lifeStages as $species => $stages)
                                            @foreach($stages as $stage)
                                                <option value="{{ $stage }}" data-species="{{ $species }}" @selected($pet->age === $stage)>{{ $stage }}</option>
                                            @endforeach
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="sex" form="{{ $formId }}" class="form-select form-select-sm">
                                        <option value="">-</option>
                                        <option value="Male" @selected($pet->sex === 'Male')>Male</option>
                                        <option value="Female" @selected($pet->sex === 'Female')>Female</option>
                                    </select>
                                </td>
                                <td>
                                    <input name="weight_kg" form="{{ $formId }}" type="number" step="0.1" min="0" class="form-control form-control-sm" value="{{ $pet->weight_kg }}">
                                    <input type="hidden" name="breed_size_category" form="{{ $formId }}" value="{{ $pet->breed_size_category }}">
                                </td>
                            @else
                                <td class="fw-bold">{{ $pet->name }}</td>
                                <td>{{ $pet->species }}</td>
                                <td>{{ $pet->age ?? '-' }}</td>
                                <td>{{ $pet->sex ?? '-' }}</td>
                                <td>{{ $pet->weight_kg ?? '-' }}</td>
                            @endif
                            <td class="small">
                                @forelse($ofOrder as $pack)
                                    <span class="badge bg-secondary me-1">{{ $pack->reference }}</span>
                                @empty
                                    <span class="text-muted">-</span>
                                @endforelse
                                @if(($packsByPet[$pet->id] ?? 0) > $ofOrder->count())
                                    <div class="text-muted">{{ ($packsByPet[$pet->id] ?? 0) - $ofOrder->count() }} other pack(s)</div>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if($canUpdate)
                                    <button class="btn btn-sm btn-outline-primary" form="{{ $formId }}" title="Save">
                                        <i class="fas fa-save"></i>
                                    </button>
                                    @if($canRemove)
                                        <form method="POST" action="{{ route('auto-subscriptions.pets.destroy', [$order, $pet]) }}" class="d-inline"
                                              onsubmit="return confirm('Remove {{ e($pet->name) }}? (no pack, no order)');">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="fas fa-trash"></i></button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted text-center py-3">No pet yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white small text-muted">
            Qty 2 for one cat by default? Add the second pet and tick the pack, or change the pet on the pack below.
            A pet can only be removed when it has no pack and no order.
        </div>
    </div>
@endif

{{-- PURCHASES + DELIVERIES EDITOR --}}
@foreach($purchases as $purchase)
    @php
        $recipes = $editor[$purchase->id]['recipes'];
        $editable = $editor[$purchase->id]['editable'];
        $statusConfig = \App\Models\Purchase::STATUSES[$purchase->status] ?? ['label' => $purchase->status, 'color' => 'secondary'];
        $deliveries = $purchase->deliveries;
        $scheduled = $deliveries->sum(fn ($d) => $d->items->sum('quantity'));
    @endphp

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <a href="{{ route('purchases.show', $purchase) }}" class="fw-bold">{{ $purchase->reference }}</a>
                · {{ $purchase->product_name }}
                · <i class="fas fa-{{ $purchase->species === 'Dog' ? 'dog' : 'cat' }} text-mauve"></i> {{ $purchase->animal_name }}
                <span class="badge bg-{{ $statusConfig['color'] }} ms-1">{{ $statusConfig['label'] }}</span>
                <div class="small text-muted">
                    {{ $scheduled }} / {{ $purchase->total_recipes }} recipes planned
                    · {{ $purchase->min_deliveries === $purchase->max_deliveries ? $purchase->min_deliveries : $purchase->min_deliveries . '-' . $purchase->max_deliveries }} deliveries
                    · {{ $purchase->min_recipes_per_delivery === $purchase->max_recipes_per_delivery ? $purchase->min_recipes_per_delivery : $purchase->min_recipes_per_delivery . '-' . $purchase->max_recipes_per_delivery }} recipes / delivery
                    · {{ number_format((float) $purchase->total_amount, 3) }} {{ $purchase->currency }}
                </div>
            </div>

            @if($canUpdate)
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    {{-- Pack given to another pet of the customer --}}
                    @php $samePets = $pets->where('species', $purchase->species); @endphp
                    @if($samePets->count() > 1 || ($samePets->count() === 1 && (int) $samePets->first()->id !== (int) $purchase->animal_id))
                        <form method="POST" action="{{ route('auto-subscriptions.pet', [$order, $purchase]) }}"
                              class="d-flex gap-2 align-items-center border rounded px-2 py-1 bg-light">
                            @csrf
                            <span class="small text-muted text-nowrap"><i class="fas fa-paw me-1"></i>Pet</span>
                            <select name="animal_id" class="form-select form-select-sm" style="min-width:130px">
                                @foreach($samePets as $pet)
                                    <option value="{{ $pet->id }}" @selected((int) $purchase->animal_id === (int) $pet->id)>{{ $pet->name }}{{ $pet->age ? ' (' . $pet->age . ')' : '' }}</option>
                                @endforeach
                            </select>
                            <div class="form-check mb-0 text-nowrap" title="Pack 2/2 of the same cat starts after pack 1: for another pet, the dates start again now">
                                <input class="form-check-input" type="checkbox" name="replan" value="1" id="replan{{ $purchase->id }}" checked>
                                <label class="form-check-label small" for="replan{{ $purchase->id }}">Plan the dates again</label>
                            </div>
                            <button class="btn btn-sm btn-outline-primary text-nowrap"><i class="fas fa-exchange-alt me-1"></i>Change</button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('auto-subscriptions.replan', [$order, $purchase]) }}"
                          onsubmit="return confirm('Replace the editable deliveries with an automatic planning?');">
                        @csrf
                        <button class="btn btn-sm btn-outline-primary"><i class="fas fa-magic me-1"></i>Plan automatically</button>
                    </form>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('auto-subscriptions.deliveries', [$order, $purchase]) }}">
            @csrf
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:150px">Date</th>
                            <th style="min-width:120px">Slot</th>
                            <th style="min-width:150px">Address</th>
                            @foreach($recipes as $recipeId => $row)
                                <th class="text-center small" style="min-width:80px">
                                    {{ \Illuminate\Support\Str::limit($row['recipe']->name, 18) }}
                                    @if($row['max_quantity'] !== null)
                                        <div class="text-muted fw-normal">max {{ $row['max_quantity'] }}</div>
                                    @endif
                                </th>
                            @endforeach
                            <th class="text-center">Total</th>
                            <th class="text-center">Remove</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $rowIndex = 0; @endphp

                        @foreach($deliveries as $delivery)
                            @php
                                $canEdit = $canUpdate && ($editable[$delivery->id] ?? false);
                                $quantities = $delivery->items->groupBy('recipe_id')->map(fn ($items) => $items->sum('quantity'));
                                $deliveryStatus = \App\Models\PurchaseDelivery::STATUSES[$delivery->status] ?? ['label' => $delivery->status, 'color' => 'secondary'];
                            @endphp
                            <tr class="{{ $canEdit ? '' : 'table-light' }}">
                                <td>
                                    @if($canEdit)
                                        <input type="hidden" name="deliveries[{{ $rowIndex }}][id]" value="{{ $delivery->id }}">
                                        <input type="date" name="deliveries[{{ $rowIndex }}][date]" class="form-control form-control-sm"
                                               value="{{ $delivery->delivery_date?->format('Y-m-d') }}" min="{{ $earliest->format('Y-m-d') }}">
                                    @else
                                        {{ $delivery->delivery_date?->format('D d/m/Y') }}
                                        <span class="badge bg-{{ $deliveryStatus['color'] }}">{{ $deliveryStatus['label'] }}</span>
                                        <div class="small text-muted"><i class="fas fa-lock me-1"></i>locked</div>
                                    @endif
                                </td>
                                <td>
                                    @if($canEdit)
                                        <select name="deliveries[{{ $rowIndex }}][delivery_slot]" class="form-select form-select-sm">
                                            <option value="">Address slot</option>
                                            @foreach($slots as $slot)
                                                <option value="{{ $slot }}" @selected($delivery->delivery_slot === $slot)>{{ $slot }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="small">{{ $delivery->delivery_slot ?? '-' }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($canEdit)
                                        <select name="deliveries[{{ $rowIndex }}][address_id]" class="form-select form-select-sm">
                                            @foreach($addresses as $address)
                                                <option value="{{ $address->id }}" @selected((int) $delivery->customer_address_id === (int) $address->id)>
                                                    {{ \Illuminate\Support\Str::limit($address->label ?: $address->address, 28) }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="small">{{ \Illuminate\Support\Str::limit($delivery->customerAddress?->label ?: $delivery->customerAddress?->address, 28) ?: '-' }}</span>
                                    @endif
                                </td>
                                @foreach($recipes as $recipeId => $row)
                                    <td class="text-center">
                                        @if($canEdit)
                                            <input type="number" min="0" name="deliveries[{{ $rowIndex }}][items][{{ $recipeId }}]"
                                                   value="{{ $quantities[$recipeId] ?? 0 }}" class="form-control form-control-sm text-center qty-input"
                                                   style="max-width:70px;margin:auto">
                                        @else
                                            {{ $quantities[$recipeId] ?? 0 }}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="text-center fw-bold row-total">{{ $quantities->sum() }}</td>
                                <td class="text-center">
                                    @if($canEdit)
                                        <input type="checkbox" class="form-check-input" name="deliveries[{{ $rowIndex }}][remove]" value="1">
                                    @endif
                                </td>
                            </tr>
                            @if($canEdit)
                                @php $rowIndex++; @endphp
                            @endif
                        @endforeach

                        {{-- New delivery --}}
                        @if($canUpdate && $deliveries->count() < $purchase->max_deliveries)
                            <tr class="table-success bg-opacity-10">
                                <td>
                                    <div class="small text-success fw-bold mb-1"><i class="fas fa-plus me-1"></i>New delivery</div>
                                    <input type="date" name="deliveries[{{ $rowIndex }}][date]" class="form-control form-control-sm" min="{{ $earliest->format('Y-m-d') }}">
                                </td>
                                <td>
                                    <select name="deliveries[{{ $rowIndex }}][delivery_slot]" class="form-select form-select-sm">
                                        <option value="">Address slot</option>
                                        @foreach($slots as $slot)
                                            <option value="{{ $slot }}">{{ $slot }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="deliveries[{{ $rowIndex }}][address_id]" class="form-select form-select-sm">
                                        @foreach($addresses as $address)
                                            <option value="{{ $address->id }}" @selected($address->is_default)>
                                                {{ \Illuminate\Support\Str::limit($address->label ?: $address->address, 28) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                @foreach($recipes as $recipeId => $row)
                                    <td class="text-center">
                                        <input type="number" min="0" name="deliveries[{{ $rowIndex }}][items][{{ $recipeId }}]" value="0"
                                               class="form-control form-control-sm text-center qty-input" style="max-width:70px;margin:auto">
                                    </td>
                                @endforeach
                                <td class="text-center fw-bold row-total">0</td>
                                <td></td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            @if($canUpdate)
                <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="small text-muted">
                        Same rules as the app (pack limits, no Sunday). Staff: tomorrow can be chosen, even after 18:00.
                        Locked deliveries (prepared / past cutoff) are kept.
                    </div>
                    <button class="btn btn-mauve"><i class="fas fa-save me-1"></i>Save deliveries</button>
                </div>
            @endif
        </form>
    </div>
@endforeach

{{-- RAW JSON --}}
<div class="card shadow-sm mb-3">
    <div class="card-header bg-white">
        <a class="text-decoration-none" data-bs-toggle="collapse" href="#rawPayload">
            <i class="fas fa-code me-1"></i>Shopify data (JSON)
        </a>
    </div>
    <div class="collapse" id="rawPayload">
        <pre class="card-body small mb-0" style="max-height:420px;overflow:auto">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</div>

<script>
    // Live total of each delivery row
    document.querySelectorAll('tr').forEach(function (row) {
        const inputs = row.querySelectorAll('.qty-input');
        const total = row.querySelector('.row-total');
        if (!inputs.length || !total) return;

        const update = () => {
            total.textContent = Array.from(inputs).reduce((sum, input) => sum + (parseInt(input.value, 10) || 0), 0);
        };

        inputs.forEach(input => input.addEventListener('input', update));
    });

    // Zones of the chosen governorate only
    (function () {
        const province = document.getElementById('provinceSelect');
        const zone = document.getElementById('zoneSelect');
        if (!province || !zone) return;

        const filter = () => {
            Array.from(zone.options).forEach(option => {
                if (!option.dataset.province) return;
                const show = !province.value || option.dataset.province === province.value;
                option.hidden = !show;
                option.disabled = !show;
            });

            const selected = zone.options[zone.selectedIndex];
            if (selected && selected.disabled) zone.value = '';
        };

        // Zone chosen first: its governorate is selected
        zone.addEventListener('change', () => {
            const selected = zone.options[zone.selectedIndex];
            if (selected && selected.dataset.province && province.value !== selected.dataset.province) {
                province.value = selected.dataset.province;
                filter();
            }
        });

        province.addEventListener('change', filter);
        filter();
    })();

    // Pet forms: ages of the species (Kitten = cat, Puppy = dog), dog size only for dogs
    document.querySelectorAll('.species-select').forEach(function (species) {
        const formId = species.dataset.form;
        const scope = formId ? document : species.closest('form');
        const age = formId
            ? document.querySelector('.age-select[data-form="' + formId + '"]')
            : scope.querySelector('.age-select');

        const update = () => {
            if (age) {
                Array.from(age.options).forEach(option => {
                    if (!option.dataset.species) return;
                    const show = option.dataset.species === species.value;
                    option.hidden = !show;
                    option.disabled = !show;
                });

                const selected = age.options[age.selectedIndex];
                if (selected && selected.disabled) age.value = '';
            }

            if (!formId) {
                scope.querySelectorAll('.dog-only').forEach(el => el.style.display = species.value === 'Dog' ? '' : 'none');
            }
        };

        species.addEventListener('change', update);
        update();
    });
</script>
@endsection

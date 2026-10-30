@extends('layouts.app')

@section('title', 'Purchase ' . $purchase->reference)

@section('content')
@php
    $authenticatedUser = auth()->user();
    $canUpdatePurchase = $authenticatedUser && $authenticatedUser->hasPermission('purchases.update');
    $canChangeStatus = $authenticatedUser && $authenticatedUser->hasPermission('purchases.update_status');

    $statuses = \App\Models\Purchase::STATUSES;
    $transitions = $purchase->allowedTransitions();

    $remainingRecipes = max(0, $purchase->total_recipes - $scheduledRecipes);
    $usagePercent = $purchase->total_recipes > 0
        ? min(100, round($scheduledRecipes / $purchase->total_recipes * 100))
        : 0;

    $activeDeliveries = $purchase->deliveries->where('status', '!=', 'cancelled')->count();
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h2 class="text-mauve mb-1">
            Purchase {{ $purchase->reference }}
            <span class="badge bg-{{ \App\Models\Purchase::statusColor($purchase->status) }} fs-6 align-middle">
                {{ \App\Models\Purchase::statusLabel($purchase->status) }}
            </span>
        </h2>
        <div class="text-muted small">
            {{ $purchase->purchased_at?->format('d/m/Y H:i') }} · {{ $purchase->source_label }}
            @if($purchase->createdBy)
                · by {{ $purchase->createdBy->first_name }} {{ $purchase->createdBy->last_name }}
            @endif
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="{{ route('purchases.index') }}" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Back
        </a>
        @if($canUpdatePurchase)
            <a href="{{ route('purchases.edit', $purchase) }}" class="btn btn-outline-primary">
                <i class="fas fa-edit me-1"></i>Edit
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

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0 small">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    {{-- ============================================================ --}}
    {{-- LEFT                                                          --}}
    {{-- ============================================================ --}}
    <div class="col-lg-8">

        {{-- PREMIUM-... pack: untreated / treated --}}
        @include('purchases._premium')

        <div class="row g-3 mb-3">
            {{-- CUSTOMER --}}
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-yellow py-2"><strong><i class="fas fa-user me-1"></i>Customer</strong></div>
                    <div class="card-body small">
                        <div class="fw-semibold fs-6">{{ $purchase->customer_name ?: '-' }}</div>
                        <div><i class="fas fa-phone me-1 text-muted"></i>{{ $purchase->customer_phone ?: '-' }}</div>

                        @if($purchase->subscription)
                            <div class="mt-1">
                                Subscription
                                <a href="{{ route('subscriptions.edit', $purchase->subscription) }}">{{ $purchase->subscription->code }}</a>
                            </div>
                            @if(!$purchase->subscription->customerAccount)
                                <div class="text-warning mt-1">
                                    <i class="fas fa-exclamation-triangle me-1"></i>No app account: the customer cannot plan deliveries in the app.
                                </div>
                            @endif
                        @else
                            <div class="text-muted mt-1">Subscription deleted.</div>
                        @endif

                        <hr class="my-2">

                        <div>
                            <i class="fas fa-{{ $purchase->species === 'Dog' ? 'dog' : 'cat' }} me-1 text-mauve"></i>
                            <strong>{{ $purchase->animal_name ?: 'No specific pet' }}</strong>
                            @if($purchase->animal)
                                <span class="text-muted">
                                    ({{ $purchase->animal->species }}{{ $purchase->animal->breed_size_category ? ', ' . $purchase->animal->breed_size_category : '' }})
                                </span>
                            @elseif($purchase->animal_name)
                                <span class="text-muted">(pet deleted)</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- PRODUCT --}}
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-yellow py-2"><strong><i class="fas fa-box-open me-1"></i>Product</strong></div>
                    <div class="card-body small">
                        <div class="fw-semibold fs-6">
                            {{ $purchase->product_name }}
                            @if($purchase->product_type === 'premium')
                                <span class="badge bg-warning text-dark">Premium</span>
                            @endif
                            @if($purchase->is_trial)
                                <span class="badge bg-info text-dark">Trial</span>
                            @endif
                        </div>
                        @if($purchase->product_name_ar)
                            <div dir="rtl" class="text-muted">{{ $purchase->product_name_ar }}</div>
                        @endif
                        <div class="text-muted">
                            {{ $purchase->product_code }}
                            @if($purchase->product && !$purchase->product->trashed())
                                · <a href="{{ route('products.edit', $purchase->product) }}">current product</a>
                            @elseif(!$purchase->product || $purchase->product->trashed())
                                · product deleted
                            @endif
                        </div>

                        <hr class="my-2">

                        <div><strong>{{ $purchase->total_recipes }}</strong> recipes over {{ $purchase->durationLabel() }}</div>
                        <div>
                            Deliveries:
                            {{ $purchase->min_deliveries === $purchase->max_deliveries ? $purchase->min_deliveries : $purchase->min_deliveries . '–' . $purchase->max_deliveries }}
                            · Recipes / delivery:
                            {{ $purchase->min_recipes_per_delivery === $purchase->max_recipes_per_delivery ? $purchase->min_recipes_per_delivery : $purchase->min_recipes_per_delivery . '–' . $purchase->max_recipes_per_delivery }}
                        </div>
                        @if($purchase->min_days_between_deliveries)
                            <div>At least {{ $purchase->min_days_between_deliveries }} day(s) between deliveries</div>
                        @endif
                        <div class="text-muted mt-1">Rules copied at purchase time.</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- USAGE --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2 d-flex justify-content-between">
                <strong><i class="fas fa-truck me-1"></i>Deliveries</strong>
                <span class="small">
                    {{ $activeDeliveries }} / {{ $purchase->max_deliveries }} deliveries
                </span>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between small mb-1">
                    <span>{{ $scheduledRecipes }} recipe(s) scheduled</span>
                    <span>{{ $remainingRecipes }} remaining / {{ $purchase->total_recipes }}</span>
                </div>
                <div class="progress mb-3" style="height: 10px;">
                    <div class="progress-bar bg-success" style="width: {{ $usagePercent }}%"></div>
                </div>

                <div class="d-flex flex-wrap gap-3 small mb-3">
                    <span><strong>Start:</strong> {{ $purchase->starts_on?->format('d/m/Y') ?? '—' }}</span>
                    <span><strong>End:</strong> {{ $purchase->ends_on?->format('d/m/Y') ?? '—' }}</span>
                    @if($purchase->ends_on && in_array($purchase->status, ['paid', 'active'], true))
                        @php $daysLeft = (int) now()->startOfDay()->diffInDays($purchase->ends_on, false); @endphp
                        <span class="{{ $daysLeft < 0 ? 'text-danger' : ($daysLeft <= 7 ? 'text-warning' : 'text-muted') }}">
                            {{ $daysLeft < 0 ? 'Ended ' . abs($daysLeft) . ' day(s) ago' : $daysLeft . ' day(s) left' }}
                        </span>
                    @endif
                </div>

                @if($purchase->deliveries->isEmpty())
                    <div class="text-center text-muted small py-3">
                        <i class="fas fa-calendar-plus me-1"></i>
                        @if(in_array($purchase->status, ['paid', 'active'], true))
                            The customer has not planned the deliveries yet (mobile app).
                        @else
                            Deliveries can be planned once the purchase is paid.
                        @endif
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Slot</th>
                                    <th>Recipes</th>
                                    <th>Address</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($purchase->deliveries as $delivery)
                                    @php $deliveryStatus = \App\Models\PurchaseDelivery::STATUSES[$delivery->status] ?? ['label' => $delivery->status, 'color' => 'secondary']; @endphp
                                    <tr class="{{ $delivery->status === 'cancelled' ? 'text-muted text-decoration-line-through' : '' }}">
                                        <td>{{ $delivery->delivery_number }}</td>
                                        <td class="text-nowrap">{{ $delivery->delivery_date?->format('D d/m/Y') }}</td>
                                        <td class="small">{{ $delivery->delivery_slot ?? '-' }}</td>
                                        <td class="small">
                                            @foreach($delivery->items as $item)
                                                <div>{{ $item->quantity }} × {{ $item->recipe_name }}</div>
                                            @endforeach
                                            <span class="badge bg-mauve">{{ $delivery->items->sum('quantity') }}</span>
                                        </td>
                                        <td class="small">
                                            {{ $delivery->customerAddress?->label ?: \Illuminate\Support\Str::limit($delivery->customerAddress?->address, 30) ?: '-' }}
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ $deliveryStatus['color'] }}">{{ $deliveryStatus['label'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- NOTES --}}
        @if($purchase->customer_note || $purchase->admin_note)
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-yellow py-2"><strong><i class="fas fa-sticky-note me-1"></i>Notes</strong></div>
                <div class="card-body small">
                    @if($purchase->customer_note)
                        <div class="mb-2"><strong>Customer:</strong> {!! nl2br(e($purchase->customer_note)) !!}</div>
                    @endif
                    @if($purchase->admin_note)
                        <div><strong>Internal:</strong> {!! nl2br(e($purchase->admin_note)) !!}</div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- ============================================================ --}}
    {{-- RIGHT                                                         --}}
    {{-- ============================================================ --}}
    <div class="col-lg-4">

        {{-- PAYMENT --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2"><strong><i class="fas fa-coins me-1"></i>Payment</strong></div>
            <div class="card-body small">
                <div class="d-flex justify-content-between"><span>Price</span><span>{{ number_format($purchase->unit_price, 3) }}</span></div>
                @if((float) $purchase->discount_amount > 0)
                    <div class="d-flex justify-content-between text-success"><span>Discount</span><span>-{{ number_format($purchase->discount_amount, 3) }}</span></div>
                @endif
                <div class="d-flex justify-content-between fs-6 fw-bold border-top pt-1 mt-1">
                    <span>Total</span><span>{{ number_format($purchase->total_amount, 3) }} {{ $purchase->currency }}</span>
                </div>

                <hr class="my-2">

                <div><strong>Method:</strong> {{ $purchase->payment_method_label ?? '—' }}</div>
                <div><strong>Reference:</strong> {{ $purchase->payment_reference ?: '—' }}</div>
                <div><strong>Paid at:</strong> {{ $purchase->paid_at?->format('d/m/Y H:i') ?? '—' }}</div>
                @if($purchase->shopify_order_name || $purchase->shopify_order_id)
                    <div><strong>Shopify:</strong> {{ $purchase->shopify_order_name }} {{ $purchase->shopify_order_id ? '(' . $purchase->shopify_order_id . ')' : '' }}</div>
                @endif
                @if($purchase->payment_proof_url)
                    <a href="{{ $purchase->payment_proof_url }}" target="_blank" class="btn btn-sm btn-outline-secondary mt-2">
                        <i class="fas fa-paperclip me-1"></i>Payment proof
                    </a>
                @endif
            </div>
        </div>

        {{-- STATUS CHANGE --}}
        @if($canChangeStatus)
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-yellow py-2"><strong><i class="fas fa-exchange-alt me-1"></i>Change status</strong></div>
                <div class="card-body">
                    @if(empty($transitions))
                        <div class="text-muted small">No status change possible from <strong>{{ \App\Models\Purchase::statusLabel($purchase->status) }}</strong>.</div>
                    @else
                        <form method="POST" action="{{ route('purchases.status', $purchase) }}" id="statusForm">
                            @csrf

                            <div class="d-flex flex-wrap gap-2 mb-2">
                                @foreach($transitions as $status)
                                    <input type="radio" class="btn-check" name="status" id="status_{{ $status }}"
                                           value="{{ $status }}" autocomplete="off" @checked(old('status') === $status) required>
                                    <label class="btn btn-sm btn-outline-{{ $statuses[$status]['color'] }}" for="status_{{ $status }}">
                                        {{ $statuses[$status]['label'] }}
                                    </label>
                                @endforeach
                            </div>

                            <textarea name="note" rows="2" class="form-control form-control-sm mb-2 @error('note') is-invalid @enderror"
                                      id="statusNote" placeholder="Note (optional)">{{ old('note') }}</textarea>
                            @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror

                            <div class="small text-muted mb-2" id="statusHelp"></div>

                            <button type="submit" class="btn btn-mauve btn-sm w-100">Apply</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        {{-- HISTORY --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2"><strong><i class="fas fa-history me-1"></i>Status history</strong></div>
            <div class="card-body">
                @forelse($purchase->statusHistories as $history)
                    <div class="history-item">
                        <div class="history-dot bg-{{ \App\Models\Purchase::statusColor($history->to_status) }}"></div>
                        <div class="small">
                            <div>
                                @if($history->from_status)
                                    <span class="text-muted">{{ \App\Models\Purchase::statusLabel($history->from_status) }}</span>
                                    <i class="fas fa-arrow-right mx-1 text-muted"></i>
                                @endif
                                <strong>{{ \App\Models\Purchase::statusLabel($history->to_status) }}</strong>
                            </div>
                            <div class="text-muted">
                                {{ $history->created_at?->format('d/m/Y H:i') }} · {{ $history->actor_label }}
                            </div>
                            @if($history->note)
                                <div class="history-note">{!! nl2br(e($history->note)) !!}</div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="text-muted small">No history.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<style>
.history-item {
    position: relative;
    padding-left: 22px;
    padding-bottom: 14px;
    border-left: 2px solid rgba(0,0,0,0.08);
    margin-left: 6px;
}
.history-item:last-child { border-left-color: transparent; padding-bottom: 0; }
.history-dot {
    position: absolute;
    left: -7px;
    top: 3px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px rgba(0,0,0,0.12);
}
.history-note {
    margin-top: 4px;
    padding: 4px 8px;
    border-radius: 6px;
    background: #f8f9fa;
}
</style>

@if($canChangeStatus && !empty($transitions))
<script>
document.addEventListener('DOMContentLoaded', function () {
    var help = {
        paid: 'Payment confirmed. The customer can now plan the deliveries. The payment date is set to now if empty.',
        active: 'The pack period starts (today if no start date) and the end date is computed from the duration.',
        completed: 'All recipes have been delivered.',
        expired: 'The pack period is over.',
        cancelled: 'Reason required.',
        refunded: 'Reason required (amount, method...).',
        pending: 'Back to pending payment.'
    };
    var requiresNote = @json(\App\Models\Purchase::STATUSES_REQUIRING_NOTE);

    document.querySelectorAll('#statusForm input[name="status"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.getElementById('statusHelp').textContent = help[this.value] || '';
            var note = document.getElementById('statusNote');
            note.required = requiresNote.indexOf(this.value) !== -1;
            note.placeholder = note.required ? 'Reason (required)' : 'Note (optional)';
        });
    });

    document.getElementById('statusForm').addEventListener('submit', function (e) {
        var selected = document.querySelector('#statusForm input[name="status"]:checked');
        if (selected && ['cancelled', 'refunded'].indexOf(selected.value) !== -1
            && !confirm('Confirm: mark this purchase as ' + selected.value + '?')) {
            e.preventDefault();
        }
    });
});
</script>
@endif
@endsection

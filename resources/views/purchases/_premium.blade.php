{{--
    PREMIUM-... pack (included in purchases/show)
    Untreated: contact the customer, create his CUSTOM-... product, assign it here.
--}}
@if(\App\Support\PremiumPack::isPremiumPurchase($purchase))
    @php
        $premiumPending = \App\Support\PremiumPack::isPending($purchase);
        $canAssign = $premiumPending && auth()->user()?->hasPermission('purchases.update');

        $customProducts = $canAssign
            ? \App\Models\Product::query()
                ->where('species', $purchase->species)
                ->where('code', 'not like', \App\Support\PremiumPack::CODE_PREFIX . '%')
                ->withCount('recipes')
                ->orderByRaw("CASE WHEN code LIKE ? THEN 0 ELSE 1 END", [\App\Support\PremiumPack::CUSTOM_PREFIX . '%'])
                ->orderByDesc('id')
                ->get()
            : collect();

        $phone = preg_replace('/\D+/', '', (string) $purchase->customer_phone);
    @endphp

    <div class="card shadow-sm mb-3 {{ $premiumPending ? 'border-warning' : 'border-success' }}" style="border-width: 2px;">
        <div class="card-header py-2 d-flex justify-content-between align-items-center {{ $premiumPending ? 'bg-warning bg-opacity-25' : 'bg-success bg-opacity-10' }}">
            <strong><i class="fas fa-crown text-warning me-1"></i>Premium pack</strong>

            @if($premiumPending)
                <span class="badge bg-danger"><i class="fas fa-clock me-1"></i>Untreated</span>
            @else
                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Treated</span>
            @endif
        </div>

        <div class="card-body small">
            @if($premiumPending)
                <div class="alert alert-warning py-2 mb-3">
                    <i class="fas fa-info-circle me-1"></i>
                    The customer <b>cannot choose recipes or delivery days</b>.
                    <ol class="mb-0 mt-1 ps-3">
                        <li>Contact the customer and receive the documents of his pet.</li>
                        <li>Create his product in <b>Products</b> with his recipes. Code <b>CUSTOM-...</b> (never shown in the app), same species.</li>
                        <li>Choose this product below: the customer is notified and can plan his deliveries.</li>
                    </ol>
                </div>

                @if($phone)
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <a href="https://wa.me/{{ $phone }}" target="_blank" class="btn btn-sm btn-success">
                            <i class="fab fa-whatsapp me-1"></i>WhatsApp {{ $purchase->customer_phone }}
                        </a>
                        <a href="tel:{{ $purchase->customer_phone }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-phone me-1"></i>Call
                        </a>
                    </div>
                @endif

                @if($canAssign)
                    <form method="POST" action="{{ route('purchases.premium-product', $purchase) }}"
                          onsubmit="return confirm('Assign this product to the purchase and notify the customer?');">
                        @csrf

                        <label class="form-label fw-semibold mb-1">Custom product of the customer</label>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-8">
                                <select name="product_id" class="form-select form-select-sm" required>
                                    <option value="">Choose the product...</option>
                                    @foreach($customProducts as $product)
                                        <option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>
                                            {{ $product->code }} · {{ $product->name }}
                                            ({{ $product->total_recipes }} recipes · {{ $product->recipes_count }} recipe types)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 d-grid">
                                <button class="btn btn-sm btn-mauve">
                                    <i class="fas fa-check me-1"></i>Assign &amp; mark treated
                                </button>
                            </div>
                        </div>

                        <div class="form-check mt-2">
                            <input type="hidden" name="notify" value="0">
                            <input class="form-check-input" type="checkbox" name="notify" value="1" id="premiumNotify" checked>
                            <label class="form-check-label" for="premiumNotify">
                                Notify the customer in the app ("Your custom recipes are ready")
                            </label>
                        </div>

                        <div class="text-muted mt-1">
                            The price paid, the payment and the pet do not change. The recipes and delivery rules come from the new product.
                        </div>
                    </form>
                @endif
            @else
                <div class="row g-2">
                    <div class="col-md-6">
                        <div class="text-muted">Bought</div>
                        <div class="fw-semibold">{{ $purchase->premium_original_product_code ?: '-' }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted">Custom product</div>
                        <div class="fw-semibold">{{ $purchase->product_code }} · {{ $purchase->product_name }}</div>
                    </div>
                    <div class="col-12 text-muted">
                        Treated {{ $purchase->premium_processed_at?->format('d/m/Y H:i') }}
                        @if($purchase->premiumProcessedBy) by {{ $purchase->premiumProcessedBy->name }} @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
@endif

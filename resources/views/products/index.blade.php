@extends('layouts.app')

@section('content')
@php
    $authenticatedUser = auth()->user();

    $canCreateProduct = $authenticatedUser && $authenticatedUser->hasPermission('products.create');
    $canUpdateProduct = $authenticatedUser && $authenticatedUser->hasPermission('products.update');
    $canDeleteProduct = $authenticatedUser && $authenticatedUser->hasPermission('products.delete');

    $hasFilters = collect($filters)->filter(fn ($value) => $value !== '' && $value !== null)->isNotEmpty();
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="text-mauve mb-0">Products</h2>

    @if($canCreateProduct)
        <a href="{{ route('products.create') }}" class="btn btn-mauve">
            <i class="fas fa-plus me-2"></i>Add Product
        </a>
    @endif
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- FILTERS --}}
<div class="card shadow mb-3">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('products.index') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Search (Code / Name)</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control"
                           value="{{ $filters['search'] }}" placeholder="Type to search...">
                </div>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Species</label>
                <select name="species" class="form-select">
                    <option value="">All</option>
                    <option value="Cat" @selected($filters['species'] === 'Cat')>Cat</option>
                    <option value="Dog" @selected($filters['species'] === 'Dog')>Dog</option>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Type</label>
                <select name="type" class="form-select">
                    <option value="">All</option>
                    <option value="regular" @selected($filters['type'] === 'regular')>Regular</option>
                    <option value="premium" @selected($filters['type'] === 'premium')>Premium</option>
                </select>
            </div>

            <div class="col-6 col-md-1">
                <label class="form-label small mb-1">Trial</label>
                <select name="trial" class="form-select">
                    <option value="">All</option>
                    <option value="1" @selected($filters['trial'] === '1')>Trial</option>
                    <option value="0" @selected($filters['trial'] === '0')>Not trial</option>
                </select>
            </div>

            <div class="col-6 col-md-1">
                <label class="form-label small mb-1">Age</label>
                <select name="age" class="form-select">
                    <option value="">All</option>
                    @foreach(\App\Models\Product::LIFE_STAGES as $stage)
                        <option value="{{ $stage }}" @selected(($filters['age'] ?? '') === $stage)>{{ $stage }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-1">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="active" @selected($filters['status'] === 'active')>Active</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-mauve flex-fill">
                    <i class="fas fa-filter me-1"></i>Filter
                </button>
                @if($hasFilters)
                    <a href="{{ route('products.index') }}" class="btn btn-outline-secondary" title="Reset">
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
        <div class="small text-muted mb-2">{{ $products->count() }} product(s)</div>

        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:64px;"></th>
                        <th>Product</th>
                        <th>Species</th>
                        <th>Type</th>
                        <th class="text-end">Price</th>
                        <th>Duration</th>
                        <th class="text-center">Recipes</th>
                        <th class="text-center">Deliveries</th>
                        <th class="text-center">Per delivery</th>
                        <th class="text-center">Status</th>
                        @if($canUpdateProduct || $canDeleteProduct)
                            <th class="text-end">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                        <tr class="{{ $product->is_active ? '' : 'product-inactive' }}">
                            <td>
                                @if($product->image_url)
                                    <img src="{{ $product->image_url }}" alt="" class="product-thumb">
                                @else
                                    <div class="product-thumb product-thumb-empty">
                                        <i class="fas fa-{{ $product->species === 'Dog' ? 'dog' : 'cat' }}"></i>
                                    </div>
                                @endif
                            </td>

                            <td>
                                <div class="fw-semibold">{{ $product->name }}</div>
                                @if($product->name_ar)
                                    <div class="small text-muted" dir="rtl">{{ $product->name_ar }}</div>
                                @endif
                                <div class="small text-muted">{{ $product->code }}</div>
                            </td>

                            <td>
                                <i class="fas fa-{{ $product->species === 'Dog' ? 'dog' : 'cat' }} me-1 text-mauve"></i>
                                {{ $product->species }}
                                @if($product->species === 'Dog')
                                    <div class="small text-muted">{{ $product->breed_size_category ?? 'All sizes' }}</div>
                                @endif
                                <div class="small text-muted">
                                    <i class="fas fa-birthday-cake me-1"></i>{{ $product->lifeStagesLabel() }}
                                </div>
                            </td>

                            <td>
                                @if($product->product_type === 'premium')
                                    <span class="badge bg-warning text-dark"><i class="fas fa-crown me-1"></i>Premium</span>
                                @else
                                    <span class="badge bg-secondary">Regular</span>
                                @endif

                                @if($product->is_trial)
                                    <span class="badge bg-info text-dark">Trial</span>
                                @endif
                            </td>

                            <td class="text-end text-nowrap">
                                @if($product->hasActivePromo())
                                    <div class="text-muted small text-decoration-line-through">
                                        {{ number_format($product->price, 3) }}
                                    </div>
                                    <div class="fw-bold text-success">
                                        {{ number_format($product->currentPrice(), 3) }} {{ $product->currency }}
                                        <span class="badge bg-success">-{{ $product->discountPercent() }}%</span>
                                    </div>
                                @else
                                    <span class="fw-semibold">{{ number_format($product->price, 3) }} {{ $product->currency }}</span>
                                    @if($product->promo_price !== null)
                                        <div class="small text-muted" title="Promo outside its dates">
                                            Promo {{ number_format($product->promo_price, 3) }} (inactive)
                                        </div>
                                    @endif
                                @endif
                                @if($product->hasTrialOffer())
                                    <div class="small text-info" title="Price for pets with a trial still running">
                                        <i class="fas fa-flask me-1"></i>During trial: {{ number_format($product->trial_offer_price, 3) }}
                                    </div>
                                @endif
                            </td>

                            <td class="text-nowrap">{{ $product->durationLabel() }}</td>

                            <td class="text-center">
                                <span class="badge bg-mauve">{{ $product->total_recipes }}</span>
                                <div class="small text-muted">{{ $product->recipes_count }} choice(s)</div>
                            </td>

                            <td class="text-center text-nowrap">
                                {{ $product->min_deliveries === $product->max_deliveries
                                    ? $product->min_deliveries
                                    : $product->min_deliveries . ' – ' . $product->max_deliveries }}
                            </td>

                            <td class="text-center text-nowrap">
                                {{ $product->min_recipes_per_delivery === $product->max_recipes_per_delivery
                                    ? $product->min_recipes_per_delivery
                                    : $product->min_recipes_per_delivery . ' – ' . $product->max_recipes_per_delivery }}
                            </td>

                            <td class="text-center">
                                @if($canUpdateProduct)
                                    <form method="POST" action="{{ route('products.toggle-active', $product) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit"
                                                class="btn btn-sm {{ $product->is_active ? 'btn-success' : 'btn-outline-secondary' }}"
                                                title="{{ $product->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                            {{ $product->is_active ? 'Active' : 'Inactive' }}
                                        </button>
                                    </form>
                                @else
                                    <span class="badge {{ $product->is_active ? 'bg-success' : 'bg-secondary' }}">
                                        {{ $product->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                @endif
                            </td>

                            @if($canUpdateProduct || $canDeleteProduct)
                                <td class="text-end text-nowrap">
                                    @if($canUpdateProduct)
                                        <a href="{{ route('products.edit', $product) }}"
                                           class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    @endif

                                    @if($canDeleteProduct)
                                        <form method="POST" action="{{ route('products.destroy', $product) }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Delete this product? Customers who already bought it keep their pack.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">
                                <i class="fas fa-info-circle me-1"></i>
                                {{ $hasFilters ? 'No products with current filters.' : 'No products yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.product-thumb {
    width: 52px;
    height: 52px;
    border-radius: 10px;
    object-fit: cover;
    border: 1px solid rgba(0,0,0,0.08);
}
.product-thumb-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(108, 92, 231, 0.08);
    color: #6c5ce7;
    font-size: 1.2rem;
}
.product-inactive td { opacity: .6; }
.product-inactive td:last-child,
.product-inactive td:nth-last-child(2) { opacity: 1; }
</style>
@endsection

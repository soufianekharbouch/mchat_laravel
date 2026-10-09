@extends('layouts.app')

@section('title', 'Recipe Details')

@section('content')
<div class="card shadow">
    <div class="card-header bg-mauve text-white d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-0">Recipe: {{ $recipe->name }}</h4>
        </div>
        @if($recipe->is_premium)
            <span class="premium-badge">
                <i class="fas fa-crown me-1"></i> Premium Recipe
            </span>
        @endif
    </div>
    <div class="card-body">
        <div class="row mb-4">
            <div class="col-md-6 mb-4 mb-md-0">
                <h5 class="text-mauve">Ingredients</h5>
                <ul class="list-group">
                    @foreach($recipe->ingredients as $recipeIngredient)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>{{ $recipeIngredient->ingredient->name }}</span>
                        <span class="badge bg-yellow text-dark">
                            {{ $recipeIngredient->quantity }} {{ $recipeIngredient->ingredient->unit }}
                        </span>
                    </li>
                    @endforeach
                </ul>
            </div>

            <div class="col-md-6">
                <h5 class="text-mauve">Recipe Information</h5>
                <p><strong>Created By:</strong> {{ $recipe->creator->first_name }} {{ $recipe->creator->last_name }}</p>
                <p><strong>Created At:</strong> {{ $recipe->created_at->format('Y-m-d H:i') }}</p>

                @if($recipe->is_premium && is_array($recipe->composition) && count($recipe->composition) > 0)
                    <hr>
                    <h6 class="mb-2">
                        <i class="fas fa-info-circle me-1"></i> Premium composition
                    </h6>
                    <ul class="list-group small">
                        @foreach($recipe->composition as $item)
                            @php
                                $label = $item['label'] ?? '';
                                $value = $item['value'] ?? '';
                            @endphp
                            @if($label !== '' || $value !== '')
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>{{ $label }}</span>
                                    <span class="badge bg-light text-dark border">{{ $value }}</span>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>

<style>
.premium-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    font-size: 0.8rem;
    font-weight: 600;
    border-radius: 999px;
    background: linear-gradient(135deg, #f9d976, #f39f86);
    color: #5c420a;
    border: 1px solid rgba(140,110,20,0.4);
    box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}

.premium-badge i {
    color: #b8860b;
    font-size: 0.9rem;
}

.bg-yellow {
    background-color: #ffd86b !important;
}
</style>
@endsection

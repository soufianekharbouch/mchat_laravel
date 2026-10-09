@extends('layouts.app')

@section('title', 'Premium recipes')

@section('content')
<div class="card card-sm shadow">
    <div class="card-header bg-mauve text-white py-2 center">
        <h4 class="mb-0 h5 center">
            <i class="fas fa-bolt me-1"></i> Premium recipes
        </h4>
    </div>

    <div class="card-body p-2">

        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="small">
                <strong>Date:</strong> {{ \Carbon\Carbon::parse($date)->format('M j, Y') }}
            </div>
            <a href="{{ route('daily-report.index', ['date' => $date]) }}" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to report
            </a>
        </div>

        @if(empty($recipes))
            <div class="alert alert-info small mb-0">
                No premium recipes for this date.
            </div>
        @else
            <div class="accordion" id="premiumRecipesPageAccordion">
                @foreach($recipes as $recipeId => $recipe)
                    @php
                        $qtyRecipe = (int)($recipeCounts[$recipeId] ?? 0);
                        $collapseId = 'premium_recipe_page_' . $recipeId;
                        $headingId  = 'heading_premium_recipe_page_' . $recipeId;

                        $ingredientsForRecipe = $ingredientPerRecipe[$recipeId] ?? [];
                    @endphp

                    <div class="accordion-item mb-2">
                        <h2 class="accordion-header" id="{{ $headingId }}">
                            <button class="accordion-button collapsed py-2" type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#{{ $collapseId }}"
                                    aria-expanded="false"
                                    aria-controls="{{ $collapseId }}">
                                <div class="d-flex w-100 justify-content-between align-items-center">
                                    <span class="fw-semibold small">
                                        <i class="fas fa-crown me-1 text-warning"></i>
                                        {{ $recipe->name }}
                                    </span>
                                    <span class="badge bg-mauve ms-2">Qty: {{ $qtyRecipe }}</span>
                                </div>
                            </button>
                        </h2>

                        <div id="{{ $collapseId }}" class="accordion-collapse collapse"
                             aria-labelledby="{{ $headingId }}"
                             data-bs-parent="#premiumRecipesPageAccordion">
                            <div class="accordion-body p-2">

                                @if(empty($ingredientsForRecipe))
                                    <div class="alert alert-info small mb-0">
                                        No ingredients for this premium recipe.
                                    </div>
                                @else
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered mb-0">
                                            <thead>
                                                <tr>
                                                    <th class="small">Ingredient</th>
                                                    <th class="small text-center">Unit</th>
                                                    <th class="small text-end">Total raw</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($ingredientsForRecipe as $row)
                                                    @php
                                                        $ing = $row['ingredient'] ?? null;
                                                        $totalVal = (float)($row['total_quantity'] ?? 0);
                                                        if(!$ing) continue;
                                                    @endphp
                                                    <tr>
                                                        <td class="small fw-semibold">
                                                            {{ $ing->name }}
                                                            @if($ing->yield_percentage !== null)
                                                                <div class="small text-muted">
                                                                    Yield: {{ rtrim(rtrim(number_format($ing->yield_percentage, 2, '.', ''), '0'), '.') }}%
                                                                </div>
                                                            @endif
                                                        </td>
                                                        <td class="small text-center">{{ $ing->unit }}</td>
                                                        <td class="small text-end fw-bold">{{ number_format($totalVal, 2) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif

                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

    </div>
</div>
@endsection

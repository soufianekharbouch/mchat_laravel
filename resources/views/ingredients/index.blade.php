@extends('layouts.app')

@section('title', 'Ingredients Management')

@section('content')
@php
    $authenticatedUser = auth()->user();

    $canCreate = $authenticatedUser
        && $authenticatedUser->hasPermission('ingredients.create');

    $canUpdate = $authenticatedUser
        && $authenticatedUser->hasPermission('ingredients.update');

    $canDelete = $authenticatedUser
        && $authenticatedUser->hasPermission('ingredients.delete');
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="text-mauve h4 mb-0">
        Ingredients Management
    </h2>

    @if($canCreate)
        <a
            href="{{ route('ingredients.create') }}"
            class="btn btn-mauve btn-sm"
        >
            <i class="fas fa-plus me-1"></i>
            Add Ingredient
        </a>
    @endif
</div>

@if(session('error'))
    <div
        class="alert alert-danger alert-dismissible fade show"
        role="alert"
    >
        <i class="fas fa-exclamation-triangle me-2"></i>
        {{ session('error') }}

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
            aria-label="Close"
        ></button>
    </div>
@endif

@if(session('success'))
    <div
        class="alert alert-success alert-dismissible fade show"
        role="alert"
    >
        <i class="fas fa-check me-2"></i>
        {{ session('success') }}

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
            aria-label="Close"
        ></button>
    </div>
@endif

<div class="card card-sm shadow">
    <div class="card-body p-2">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Unit</th>
                        <th>Yield %</th>
                        <th>Created By</th>
                        <th>Used In Recipes</th>
                        <th class="text-center">
                            Actions
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($ingredients as $ingredient)
                        @php
                            $usedInRecipes =
                                $ingredient->isUsedInRecipes();

                            $recipesCount =
                                $ingredient->recipeIngredients()->count();
                        @endphp

                        <tr>
                            <td class="fw-bold">
                                {{ $ingredient->code }}
                            </td>

                            <td>
                                {{ $ingredient->name }}
                            </td>

                            <td>
                                {{ $ingredient->unit }}
                            </td>

                            <td>
                                @if($ingredient->yield_percentage !== null)
                                    {{
                                        rtrim(
                                            rtrim(
                                                number_format(
                                                    $ingredient->yield_percentage,
                                                    2,
                                                    '.',
                                                    ''
                                                ),
                                                '0'
                                            ),
                                            '.'
                                        )
                                    }}%
                                @else
                                    -
                                @endif
                            </td>

                            <td>
                                <small>
                                    @if($ingredient->creator)
                                        {{ $ingredient->creator->first_name }}
                                        {{ $ingredient->creator->last_name }}
                                    @else
                                        Unknown
                                    @endif
                                </small>
                            </td>

                            <td>
                                @if($usedInRecipes)
                                    <span
                                        class="badge bg-warning text-dark"
                                        data-bs-toggle="tooltip"
                                        title="Used in {{ $recipesCount }} recipe(s)"
                                    >
                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                        Used ({{ $recipesCount }})
                                    </span>
                                @else
                                    <span class="badge bg-success">
                                        Not Used
                                    </span>
                                @endif
                            </td>

                            <td>
                                <div class="d-flex justify-content-center gap-1">
                                    @if($canUpdate)
                                        <a
                                            href="{{ route('ingredients.edit', $ingredient) }}"
                                            class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="tooltip"
                                            title="Edit ingredient"
                                        >
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    @endif

                                    @if($canDelete)
                                        @if($usedInRecipes)
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteModal{{ $ingredient->id }}"
                                                title="Ingredient cannot be deleted"
                                            >
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        @else
                                            <form
                                                action="{{ route('ingredients.destroy', $ingredient) }}"
                                                method="POST"
                                                class="d-inline"
                                                onsubmit="return confirm('Are you sure you want to delete this ingredient?');"
                                            >
                                                @csrf
                                                @method('DELETE')

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="tooltip"
                                                    title="Delete ingredient"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    @endif

                                    @if(!$canUpdate && !$canDelete)
                                        <span class="text-muted small">
                                            View only
                                        </span>
                                    @endif
                                </div>
                            </td>
                        </tr>

                        @if($canDelete && $usedInRecipes)
                            <div
                                class="modal fade"
                                id="deleteModal{{ $ingredient->id }}"
                                tabindex="-1"
                                aria-labelledby="deleteModalLabel{{ $ingredient->id }}"
                                aria-hidden="true"
                            >
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header bg-warning">
                                            <h5
                                                class="modal-title"
                                                id="deleteModalLabel{{ $ingredient->id }}"
                                            >
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                Cannot Delete Ingredient
                                            </h5>

                                            <button
                                                type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal"
                                                aria-label="Close"
                                            ></button>
                                        </div>

                                        <div class="modal-body">
                                            <p>
                                                The ingredient

                                                <strong>
                                                    "{{ $ingredient->name }}"
                                                </strong>

                                                cannot be deleted because it is used in

                                                <strong>
                                                    {{ $recipesCount }}
                                                </strong>

                                                recipe(s).
                                            </p>

                                            <p class="mb-0">
                                                Remove this ingredient from all recipes
                                                before deleting it.
                                            </p>
                                        </div>

                                        <div class="modal-footer">
                                            <button
                                                type="button"
                                                class="btn btn-secondary"
                                                data-bs-dismiss="modal"
                                            >
                                                Close
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @empty
                        <tr>
                            <td
                                colspan="7"
                                class="text-center py-4"
                            >
                                <i class="fas fa-info-circle me-2"></i>
                                No ingredients found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tooltipTriggerList = document.querySelectorAll(
        '[data-bs-toggle="tooltip"]'
    );

    tooltipTriggerList.forEach(function (tooltipTriggerElement) {
        new bootstrap.Tooltip(tooltipTriggerElement);
    });
});
</script>
@endsection
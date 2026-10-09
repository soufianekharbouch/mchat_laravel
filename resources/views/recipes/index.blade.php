@extends('layouts.app')

@section('title', 'Recipes Management')

@section('content')
@php
    $authenticatedUser = auth()->user();

    $canView = $authenticatedUser
        && $authenticatedUser->hasPermission('recipes.view');

    $canCreate = $authenticatedUser
        && $authenticatedUser->hasPermission('recipes.create');

    $canUpdate = $authenticatedUser
        && $authenticatedUser->hasPermission('recipes.update');

    $canDelete = $authenticatedUser
        && $authenticatedUser->hasPermission('recipes.delete');
@endphp

@if(!$canView)
    <div class="alert alert-danger">
        <i class="fas fa-lock me-2"></i>
        You do not have permission to view recipes.
    </div>
@else
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="text-mauve h4 mb-0">
        Recipes Management
    </h2>

    @if($canCreate)
        <a
            href="{{ route('recipes.create') }}"
            class="btn btn-mauve btn-sm"
        >
            <i class="fas fa-plus me-1"></i>
            Add Recipe
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

@foreach($recipeTypes as $type)
    <div class="card card-sm shadow mb-3">
        <div class="card-header bg-yellow text-dark py-2">
            <h6 class="mb-0 fw-bold">
                {{ $type->name }}
            </h6>
        </div>

        <div class="card-body p-2">
            @if($type->recipes->isEmpty())
                <div class="text-muted small">
                    No recipes in this type.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Ingredients Count</th>
                                <th>Created By</th>
                                <th>Used In Subscriptions</th>
                                <th class="text-center">
                                    Actions
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach($type->recipes as $recipe)
                                @php
                                    $subscriptionsCount =
                                        \App\Models\AnimalMeal::query()
                                            ->where('recipe_id', $recipe->id)
                                            ->distinct('animal_id')
                                            ->count('animal_id');

                                    $usedInSubscriptions =
                                        $subscriptionsCount > 0;
                                @endphp

                                <tr>
                                    <td>
                                        {{ $recipe->id }}
                                    </td>

                                    <td class="fw-bold">
                                        {{ $recipe->name }}
                                    </td>

                                    <td>
                                        <span class="badge bg-info text-dark">
                                            {{ $recipe->ingredients->count() }}
                                        </span>
                                    </td>

                                    <td>
                                        <small>
                                            @if($recipe->creator)
                                                {{ $recipe->creator->first_name }}
                                                {{ $recipe->creator->last_name }}
                                            @else
                                                Unknown
                                            @endif
                                        </small>
                                    </td>

                                    <td>
                                        @if($usedInSubscriptions)
                                            <span
                                                class="badge bg-warning text-dark"
                                                data-bs-toggle="tooltip"
                                                title="Used by {{ $subscriptionsCount }} animal subscription(s)"
                                            >
                                                <i class="fas fa-exclamation-triangle me-1"></i>
                                                Used
                                            </span>
                                        @else
                                            <span class="badge bg-success">
                                                Not Used
                                            </span>
                                        @endif
                                    </td>

                                    <td>
                                        <div class="d-flex justify-content-center gap-1">
                                            <a
                                                href="{{ route('recipes.show', $recipe) }}"
                                                class="btn btn-sm btn-outline-info"
                                                data-bs-toggle="tooltip"
                                                title="View recipe"
                                            >
                                                <i class="fas fa-eye"></i>
                                            </a>

                                            @if($canUpdate)
                                                <a
                                                    href="{{ route('recipes.edit', $recipe) }}"
                                                    class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="tooltip"
                                                    title="Edit recipe"
                                                >
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            @endif

                                            @if($canDelete)
                                                @if($usedInSubscriptions)
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-danger"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#deleteModal{{ $recipe->id }}"
                                                        title="Recipe cannot be deleted"
                                                    >
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                @else
                                                    <form
                                                        action="{{ route('recipes.destroy', $recipe) }}"
                                                        method="POST"
                                                        class="d-inline"
                                                        onsubmit="return confirm('Are you sure you want to delete this recipe?');"
                                                    >
                                                        @csrf
                                                        @method('DELETE')

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-danger"
                                                            data-bs-toggle="tooltip"
                                                            title="Delete recipe"
                                                        >
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                </tr>

                                @if($canDelete && $usedInSubscriptions)
                                    <div
                                        class="modal fade"
                                        id="deleteModal{{ $recipe->id }}"
                                        tabindex="-1"
                                        aria-labelledby="deleteModalLabel{{ $recipe->id }}"
                                        aria-hidden="true"
                                    >
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-warning">
                                                    <h5
                                                        class="modal-title"
                                                        id="deleteModalLabel{{ $recipe->id }}"
                                                    >
                                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                                        Cannot Delete Recipe
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
                                                        The recipe

                                                        <strong>
                                                            "{{ $recipe->name }}"
                                                        </strong>

                                                        cannot be deleted because it is used
                                                        in {{ $subscriptionsCount }}
                                                        animal subscription(s).
                                                    </p>

                                                    <p class="mb-0">
                                                        Remove this recipe from all subscriptions
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
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endforeach

@if($untypedRecipes->isNotEmpty())
    <div class="card card-sm shadow mb-3">
        <div class="card-header bg-yellow text-dark py-2">
            <h6 class="mb-0 fw-bold">
                Others
            </h6>
        </div>

        <div class="card-body p-2">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Ingredients Count</th>
                            <th>Created By</th>
                            <th>Used In Subscriptions</th>
                            <th class="text-center">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($untypedRecipes as $recipe)
                            @php
                                $subscriptionsCount =
                                    \App\Models\AnimalMeal::query()
                                        ->where('recipe_id', $recipe->id)
                                        ->distinct('animal_id')
                                        ->count('animal_id');

                                $usedInSubscriptions =
                                    $subscriptionsCount > 0;
                            @endphp

                            <tr>
                                <td>
                                    {{ $recipe->id }}
                                </td>

                                <td class="fw-bold">
                                    {{ $recipe->name }}
                                </td>

                                <td>
                                    <span class="badge bg-info text-dark">
                                        {{ $recipe->ingredients->count() }}
                                    </span>
                                </td>

                                <td>
                                    <small>
                                        @if($recipe->creator)
                                            {{ $recipe->creator->first_name }}
                                            {{ $recipe->creator->last_name }}
                                        @else
                                            Unknown
                                        @endif
                                    </small>
                                </td>

                                <td>
                                    @if($usedInSubscriptions)
                                        <span
                                            class="badge bg-warning text-dark"
                                            data-bs-toggle="tooltip"
                                            title="Used by {{ $subscriptionsCount }} animal subscription(s)"
                                        >
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            Used
                                        </span>
                                    @else
                                        <span class="badge bg-success">
                                            Not Used
                                        </span>
                                    @endif
                                </td>

                                <td>
                                    <div class="d-flex justify-content-center gap-1">
                                        <a
                                            href="{{ route('recipes.show', $recipe) }}"
                                            class="btn btn-sm btn-outline-info"
                                            data-bs-toggle="tooltip"
                                            title="View recipe"
                                        >
                                            <i class="fas fa-eye"></i>
                                        </a>

                                        @if($canUpdate)
                                            <a
                                                href="{{ route('recipes.edit', $recipe) }}"
                                                class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="tooltip"
                                                title="Edit recipe"
                                            >
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        @endif

                                        @if($canDelete)
                                            @if($usedInSubscriptions)
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteModal{{ $recipe->id }}"
                                                    title="Recipe cannot be deleted"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            @else
                                                <form
                                                    action="{{ route('recipes.destroy', $recipe) }}"
                                                    method="POST"
                                                    class="d-inline"
                                                    onsubmit="return confirm('Are you sure you want to delete this recipe?');"
                                                >
                                                    @csrf
                                                    @method('DELETE')

                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm btn-outline-danger"
                                                        data-bs-toggle="tooltip"
                                                        title="Delete recipe"
                                                    >
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>

                            @if($canDelete && $usedInSubscriptions)
                                <div
                                    class="modal fade"
                                    id="deleteModal{{ $recipe->id }}"
                                    tabindex="-1"
                                    aria-labelledby="deleteModalLabel{{ $recipe->id }}"
                                    aria-hidden="true"
                                >
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-warning">
                                                <h5
                                                    class="modal-title"
                                                    id="deleteModalLabel{{ $recipe->id }}"
                                                >
                                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                                    Cannot Delete Recipe
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
                                                    The recipe

                                                    <strong>
                                                        "{{ $recipe->name }}"
                                                    </strong>

                                                    cannot be deleted because it is used
                                                    in {{ $subscriptionsCount }}
                                                    animal subscription(s).
                                                </p>

                                                <p class="mb-0">
                                                    Remove this recipe from all subscriptions
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
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

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
@endif
@endsection
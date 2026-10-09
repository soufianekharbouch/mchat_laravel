@extends('layouts.app')

@section('title', 'Recipe Types Management')

@section('content')
@php
    $authenticatedUser = auth()->user();

    $canViewRecipeTypes = $authenticatedUser
        && $authenticatedUser->hasPermission('recipe_types.view');

    $canCreateRecipeType = $authenticatedUser
        && $authenticatedUser->hasPermission('recipe_types.create');

    $canUpdateRecipeType = $authenticatedUser
        && $authenticatedUser->hasPermission('recipe_types.update');

    $canDeleteRecipeType = $authenticatedUser
        && $authenticatedUser->hasPermission('recipe_types.delete');
@endphp

@if(!$canViewRecipeTypes)
    <div class="alert alert-danger">
        <i class="fas fa-lock me-2"></i>
        You do not have permission to view recipe types.
    </div>
@else
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="text-mauve h4 mb-0">
            Recipe Types Management
        </h2>

        @if($canCreateRecipeType)
            <a
                href="{{ route('recipe-types.create') }}"
                class="btn btn-mauve btn-sm"
            >
                <i class="fas fa-plus me-1"></i>
                Add Recipe Type
            </a>
        @endif
    </div>

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

    <div class="card card-sm shadow">
        <div class="card-body p-2">
            @if($recipeTypes->isEmpty())
                <div class="alert alert-info text-center mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    No recipe types found.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Created By</th>
                                <th>Created At</th>

                                @if($canUpdateRecipeType || $canDeleteRecipeType)
                                    <th class="text-center">
                                        Actions
                                    </th>
                                @endif
                            </tr>
                        </thead>

                        <tbody>
                            @foreach($recipeTypes as $type)
                                <tr>
                                    <td class="fw-semibold">
                                        <i class="fas fa-tag text-mauve me-1"></i>
                                        {{ $type->name }}
                                    </td>

                                    <td>
                                        @if($type->creator)
                                            <small>
                                                {{ $type->creator->first_name }}
                                                {{ $type->creator->last_name }}
                                            </small>
                                        @else
                                            <small class="text-muted">
                                                System
                                            </small>
                                        @endif
                                    </td>

                                    <td>
                                        <small>
                                            {{ $type->created_at?->format('Y-m-d H:i') }}
                                        </small>
                                    </td>

                                    @if($canUpdateRecipeType || $canDeleteRecipeType)
                                        <td class="text-center">
                                            <div class="d-flex justify-content-center gap-1">
                                                @if($canUpdateRecipeType)
                                                    <a
                                                        href="{{ route('recipe-types.edit', $type) }}"
                                                        class="btn btn-sm btn-outline-primary"
                                                        data-bs-toggle="tooltip"
                                                        title="Edit recipe type"
                                                    >
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                @endif

                                                @if($canDeleteRecipeType)
                                                    <form
                                                        action="{{ route('recipe-types.destroy', $type) }}"
                                                        method="POST"
                                                        class="d-inline"
                                                        onsubmit="return confirm(
                                                            'Delete recipe type {{ addslashes($type->name) }} ?'
                                                        );"
                                                    >
                                                        @csrf
                                                        @method('DELETE')

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-danger"
                                                            data-bs-toggle="tooltip"
                                                            title="Delete recipe type"
                                                        >
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
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
@endif
@endsection

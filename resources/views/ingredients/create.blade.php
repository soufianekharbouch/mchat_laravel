@extends('layouts.app')

@section('title', 'Create Ingredient')

@section('content')
@php
    $authenticatedUser = auth()->user();

    $canCreateIngredient = $authenticatedUser
        && $authenticatedUser->hasPermission('ingredients.create');
@endphp

@if(!$canCreateIngredient)
    <div class="alert alert-danger">
        <i class="fas fa-lock me-2"></i>
        You do not have permission to create ingredients.
    </div>

    <a
        href="{{ route('ingredients.index') }}"
        class="btn btn-outline-secondary"
    >
        <i class="fas fa-arrow-left me-1"></i>
        Back to ingredients
    </a>
@else
    <div class="card shadow">
        <div class="card-header bg-mauve text-white">
            <h4 class="mb-0">
                Create New Ingredient
            </h4>
        </div>

        <div class="card-body">
            <form
                method="POST"
                action="{{ route('ingredients.store') }}"
            >
                @csrf

                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label
                                for="code"
                                class="form-label"
                            >
                                Code *
                            </label>

                            <input
                                type="text"
                                class="form-control @error('code') is-invalid @enderror"
                                id="code"
                                name="code"
                                value="{{ old('code') }}"
                                required
                                autofocus
                            >

                            @error('code')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="mb-3">
                            <label
                                for="name"
                                class="form-label"
                            >
                                Name *
                            </label>

                            <input
                                type="text"
                                class="form-control @error('name') is-invalid @enderror"
                                id="name"
                                name="name"
                                value="{{ old('name') }}"
                                required
                            >

                            @error('name')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="mb-3">
                            <label
                                for="unit"
                                class="form-label"
                            >
                                Unit *
                            </label>

                            <select
                                class="form-control @error('unit') is-invalid @enderror"
                                id="unit"
                                name="unit"
                                required
                            >
                                <option
                                    value="gram"
                                    {{ old('unit', 'gram') === 'gram' ? 'selected' : '' }}
                                >
                                    Gram
                                </option>

                                <option
                                    value="unit"
                                    {{ old('unit') === 'unit' ? 'selected' : '' }}
                                >
                                    Unit
                                </option>

                                <option
                                    value="ml"
                                    {{ old('unit') === 'ml' ? 'selected' : '' }}
                                >
                                    Milliliter
                                </option>

                                <option
                                    value="kg"
                                    {{ old('unit') === 'kg' ? 'selected' : '' }}
                                >
                                    Kilogram
                                </option>
                            </select>

                            @error('unit')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="mb-3">
                            <label
                                for="yield_percentage"
                                class="form-label"
                            >
                                Yield percentage (%)
                            </label>

                            <input
                                type="number"
                                class="form-control @error('yield_percentage') is-invalid @enderror"
                                id="yield_percentage"
                                name="yield_percentage"
                                min="0"
                                max="10000"
                                step="0.01"
                                placeholder="Example: 200 means quantity doubles"
                                value="{{ old('yield_percentage') }}"
                            >

                            @error('yield_percentage')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                            @enderror

                            <small class="text-muted">
                                100 = same, 200 = double, 80 = loses 20%
                            </small>
                        </div>
                    </div>
                </div>

                <button
                    type="submit"
                    class="btn btn-mauve"
                >
                    <i class="fas fa-save me-2"></i>
                    Create Ingredient
                </button>

                <a
                    href="{{ route('ingredients.index') }}"
                    class="btn btn-outline-secondary"
                >
                    Cancel
                </a>
            </form>
        </div>
    </div>
@endif
@endsection
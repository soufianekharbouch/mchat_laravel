@extends('layouts.app')

@section('title', 'Edit Ingredient')

@section('content')
<div class="card shadow">
    <div class="card-header bg-mauve text-white">
        <h4 class="mb-0">Edit Ingredient</h4>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('ingredients.update', $ingredient) }}">
            @csrf
            @method('PUT')

            <div class="row">
                <div class="col-md-3">
                    <div class="mb-3">
                        <label for="code" class="form-label">Code *</label>
                        <input type="text" class="form-control" id="code" name="code"
                               value="{{ old('code', $ingredient->code) }}" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label for="name" class="form-label">Name *</label>
                        <input type="text" class="form-control" id="name" name="name"
                               value="{{ old('name', $ingredient->name) }}" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label for="unit" class="form-label">Unit *</label>
                        <select class="form-control" id="unit" name="unit" required>
                            <option value="gram" {{ old('unit', $ingredient->unit) == 'gram' ? 'selected' : '' }}>Gram</option>
                            <option value="unit" {{ old('unit', $ingredient->unit) == 'unit' ? 'selected' : '' }}>Unit</option>
                            <option value="ml" {{ old('unit', $ingredient->unit) == 'ml' ? 'selected' : '' }}>Milliliter</option>
                            <option value="kg" {{ old('unit', $ingredient->unit) == 'kg' ? 'selected' : '' }}>Kilogram</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label for="yield_percentage" class="form-label">Yield percentage (%)</label>
                        <input
                            type="number"
                            class="form-control"
                            id="yield_percentage"
                            name="yield_percentage"
                            min="0"
                            max="10000"
                            step="0.01"
                            placeholder="Example: 200 means quantity doubles"
                            value="{{ old('yield_percentage', $ingredient->yield_percentage) }}"
                        >
                        <small class="text-muted">100 = same, 200 = double, 80 = loses 20%</small>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-mauve">
                <i class="fas fa-save me-2"></i>Update Ingredient
            </button>
            <a href="{{ route('ingredients.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
@endsection

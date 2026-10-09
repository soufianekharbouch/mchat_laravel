@php
    /** @var \App\Models\RecipeType|null $recipeType */
    $isEdit = isset($recipeType);
@endphp

<form method="POST" action="{{ $isEdit ? route('recipe-types.update', $recipeType) : route('recipe-types.store') }}">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="mb-3">
        <label for="name" class="form-label">Recipe Type Name *</label>
        <input type="text"
               class="form-control @error('name') is-invalid @enderror"
               id="name"
               name="name"
               value="{{ old('name', $recipeType->name ?? '') }}"
               required>
        @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <button type="submit" class="btn btn-mauve">
        <i class="fas fa-save me-2"></i>{{ $isEdit ? 'Update' : 'Create' }} Recipe Type
    </button>
    <a href="{{ route('recipe-types.index') }}" class="btn btn-outline-secondary">Cancel</a>
</form>

@php
    $isEdit = isset($recipe);

    $authenticatedUser = auth()->user();

    $requiredPermission = $isEdit
        ? 'recipes.update'
        : 'recipes.create';

    $canSaveRecipe = $authenticatedUser
        && $authenticatedUser->hasPermission($requiredPermission);

    $canDeleteRecipe = $isEdit
        && $authenticatedUser
        && $authenticatedUser->hasPermission('recipes.delete');

    $compositionItems = [];

    $oldLabels = old('composition_labels');
    $oldValues = old('composition_values');

    if (is_array($oldLabels) && is_array($oldValues)) {
        foreach ($oldLabels as $index => $label) {
            $value = $oldValues[$index] ?? '';

            if ($label !== '' || $value !== '') {
                $compositionItems[] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }
    } elseif (
        $isEdit
        && isset($recipe->composition)
        && is_array($recipe->composition)
    ) {
        $compositionItems = $recipe->composition;
    }

    if (empty($compositionItems)) {
        $compositionItems = [
            [
                'label' => '',
                'value' => '',
            ],
        ];
    }

    $isPremiumChecked = old(
        'is_premium',
        $isEdit ? $recipe->is_premium : false
    ) ? true : false;

    $selectedTypeId = old(
        'recipe_type_id',
        $isEdit ? $recipe->recipe_type_id : ''
    );

    $selectedLifeStage = old(
        'life_stage',
        $isEdit ? ($recipe->life_stage ?? 'All') : 'All'
    );

    $selectedSpecies = old(
        'species',
        $isEdit ? ($recipe->species ?? 'Cat') : 'Cat'
    );

    $selectedBreedSizeCategory = old(
        'breed_size_category',
        $isEdit ? ($recipe->breed_size_category ?? '') : ''
    );

    $lifeStageOptionsCat = [
        'Kitten' => 'Kitten',
        'Adult' => 'Adult',
        'Senior' => 'Senior',
        'All' => 'All',
    ];

    $lifeStageOptionsDog = [
        'Puppy' => 'Puppy',
        'Adult' => 'Adult',
        'Senior' => 'Senior',
        'All' => 'All',
    ];

    $currentLifeStageOptions = $selectedSpecies === 'Dog'
        ? $lifeStageOptionsDog
        : $lifeStageOptionsCat;
@endphp

@if(!$canSaveRecipe)
    <div class="alert alert-danger">
        <i class="fas fa-lock me-2"></i>

        @if($isEdit)
            You do not have permission to update recipes.
        @else
            You do not have permission to create recipes.
        @endif
    </div>

    <a
        href="{{ route('recipes.index') }}"
        class="btn btn-outline-secondary"
    >
        <i class="fas fa-arrow-left me-1"></i>
        Back to recipes
    </a>
@else
    <form
        method="POST"
        action="{{ $isEdit
            ? route('recipes.update', $recipe)
            : route('recipes.store')
        }}"
    >
        @csrf

        @if($isEdit)
            @method('PUT')
        @endif

        <div class="mb-3">
            <label
                for="code"
                class="form-label"
            >
                Recipe Code *
            </label>

            <input
                type="text"
                class="form-control @error('code') is-invalid @enderror"
                id="code"
                name="code"
                value="{{ old('code', $recipe->code ?? '') }}"
                required
            >

            @error('code')
                <div class="invalid-feedback">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label
                for="name"
                class="form-label"
            >
                Recipe Name *
            </label>

            <input
                type="text"
                class="form-control @error('name') is-invalid @enderror"
                id="name"
                name="name"
                value="{{ old('name', $recipe->name ?? '') }}"
                required
            >

            @error('name')
                <div class="invalid-feedback">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label
                for="species"
                class="form-label"
            >
                Species *
            </label>

            <select
                class="form-control @error('species') is-invalid @enderror"
                id="species"
                name="species"
                required
            >
                <option
                    value="Cat"
                    {{ $selectedSpecies === 'Cat' ? 'selected' : '' }}
                >
                    Cat
                </option>

                <option
                    value="Dog"
                    {{ $selectedSpecies === 'Dog' ? 'selected' : '' }}
                >
                    Dog
                </option>
            </select>

            @error('species')
                <div class="invalid-feedback">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <div
            class="mb-3 {{ $selectedSpecies === 'Dog' ? '' : 'd-none' }}"
            id="breed-size-category-wrapper"
        >
            <label
                for="breed_size_category"
                class="form-label"
            >
                Breed size category *
            </label>

            <select
                class="form-control @error('breed_size_category') is-invalid @enderror"
                id="breed_size_category"
                name="breed_size_category"
            >
                <option value="">
                    -- Select breed size category --
                </option>

                <option
                    value="Small"
                    {{ $selectedBreedSizeCategory === 'Small' ? 'selected' : '' }}
                >
                    Small
                </option>

                <option
                    value="Medium"
                    {{ $selectedBreedSizeCategory === 'Medium' ? 'selected' : '' }}
                >
                    Medium
                </option>

                <option
                    value="Large"
                    {{ $selectedBreedSizeCategory === 'Large' ? 'selected' : '' }}
                >
                    Large
                </option>
            </select>

            @error('breed_size_category')
                <div class="invalid-feedback">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label
                for="recipe_type_id"
                class="form-label"
            >
                Recipe Type
            </label>

            <select
                class="form-control @error('recipe_type_id') is-invalid @enderror"
                id="recipe_type_id"
                name="recipe_type_id"
            >
                <option value="">
                    -- Select recipe type --
                </option>

                @foreach($recipeTypes as $type)
                    <option
                        value="{{ $type->id }}"
                        {{ (string) $selectedTypeId === (string) $type->id
                            ? 'selected'
                            : ''
                        }}
                    >
                        {{ $type->name }}
                    </option>
                @endforeach
            </select>

            @error('recipe_type_id')
                <div class="invalid-feedback">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <div class="mb-3">
            <label
                for="life_stage"
                class="form-label"
            >
                Life Stage
            </label>

            <select
                class="form-control @error('life_stage') is-invalid @enderror"
                id="life_stage"
                name="life_stage"
            >
                <option value="">
                    -- Select life stage --
                </option>

                @foreach($currentLifeStageOptions as $value => $label)
                    <option
                        value="{{ $value }}"
                        {{ $selectedLifeStage === $value ? 'selected' : '' }}
                    >
                        {{ $label }}
                    </option>
                @endforeach
            </select>

            @error('life_stage')
                <div class="invalid-feedback">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <div class="form-check form-switch mb-3">
            <input
                type="checkbox"
                class="form-check-input"
                id="is_premium"
                name="is_premium"
                value="1"
                {{ $isPremiumChecked ? 'checked' : '' }}
            >

            <label
                class="form-check-label"
                for="is_premium"
            >
                Premium recipe
            </label>
        </div>

        <div
            id="composition-section"
            class="mb-3 {{ $isPremiumChecked ? '' : 'd-none' }}"
        >
            <label class="form-label">
                Composition
            </label>

            <small class="text-muted d-block mb-1">
                Example: Protein 120 g, Calcium 200 mg, etc.
            </small>

            <div id="composition-container">
                @foreach($compositionItems as $item)
                    <div class="row mb-2 composition-row">
                        <div class="col-md-5 mb-2 mb-md-0">
                            <input
                                type="text"
                                class="form-control"
                                name="composition_labels[]"
                                placeholder="Nutrient (Protein, Calcium, etc.)"
                                value="{{ $item['label'] ?? '' }}"
                            >
                        </div>

                        <div class="col-md-5 mb-2 mb-md-0">
                            <input
                                type="text"
                                class="form-control"
                                name="composition_values[]"
                                placeholder="Amount (120 g, 200 mg, etc.)"
                                value="{{ $item['value'] ?? '' }}"
                            >
                        </div>

                        <div class="col-md-2 d-flex align-items-center">
                            <button
                                type="button"
                                class="btn btn-danger w-100 remove-composition"
                            >
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <button
                type="button"
                id="add-composition"
                class="btn btn-sm btn-outline-mauve mt-2"
            >
                <i class="fas fa-plus me-1"></i>
                Add composition line
            </button>
        </div>

        <div class="mb-3">
            <label class="form-label">
                Ingredients *
            </label>

            @error('ingredients')
                <div class="alert alert-danger py-2">
                    {{ $message }}
                </div>
            @enderror

            @error('ingredients.*')
                <div class="alert alert-danger py-2">
                    {{ $message }}
                </div>
            @enderror

            @error('quantities.*')
                <div class="alert alert-danger py-2">
                    {{ $message }}
                </div>
            @enderror

            <div id="ingredients-container">
                @if(
                    $isEdit
                    && isset($recipe->ingredients)
                    && $recipe->ingredients->count() > 0
                )
                    @foreach($recipe->ingredients as $recipeIngredient)
                        <div class="row mb-2 ingredient-row">
                            <div class="col-md-6 mb-2 mb-md-0">
                                <select
                                    class="form-control"
                                    name="ingredients[]"
                                    required
                                >
                                    <option value="">
                                        Select Ingredient
                                    </option>

                                    @foreach($ingredients as $ingredient)
                                        <option
                                            value="{{ $ingredient->id }}"
                                            {{ $recipeIngredient->ingredient_id == $ingredient->id
                                                ? 'selected'
                                                : ''
                                            }}
                                        >
                                            {{ $ingredient->name }}
                                            ({{ $ingredient->unit }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-4 mb-2 mb-md-0">
                                <input
                                    type="number"
                                    class="form-control"
                                    name="quantities[]"
                                    step="0.01"
                                    min="0.01"
                                    value="{{ $recipeIngredient->quantity }}"
                                    required
                                >
                            </div>

                            <div class="col-md-2 d-flex align-items-center">
                                <button
                                    type="button"
                                    class="btn btn-danger w-100 remove-ingredient"
                                >
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    @endforeach
                @elseif(
                    is_array(old('ingredients'))
                    && count(old('ingredients')) > 0
                )
                    @foreach(old('ingredients') as $index => $oldIngredientId)
                        <div class="row mb-2 ingredient-row">
                            <div class="col-md-6 mb-2 mb-md-0">
                                <select
                                    class="form-control"
                                    name="ingredients[]"
                                    required
                                >
                                    <option value="">
                                        Select Ingredient
                                    </option>

                                    @foreach($ingredients as $ingredient)
                                        <option
                                            value="{{ $ingredient->id }}"
                                            {{ (string) $oldIngredientId === (string) $ingredient->id
                                                ? 'selected'
                                                : ''
                                            }}
                                        >
                                            {{ $ingredient->name }}
                                            ({{ $ingredient->unit }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-4 mb-2 mb-md-0">
                                <input
                                    type="number"
                                    class="form-control"
                                    name="quantities[]"
                                    step="0.01"
                                    min="0.01"
                                    value="{{ old('quantities.' . $index) }}"
                                    required
                                >
                            </div>

                            <div class="col-md-2 d-flex align-items-center">
                                <button
                                    type="button"
                                    class="btn btn-danger w-100 remove-ingredient"
                                >
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    @endforeach
                @else
                    <div class="row mb-2 ingredient-row">
                        <div class="col-md-6 mb-2 mb-md-0">
                            <select
                                class="form-control"
                                name="ingredients[]"
                                required
                            >
                                <option value="">
                                    Select Ingredient
                                </option>

                                @foreach($ingredients as $ingredient)
                                    <option value="{{ $ingredient->id }}">
                                        {{ $ingredient->name }}
                                        ({{ $ingredient->unit }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4 mb-2 mb-md-0">
                            <input
                                type="number"
                                class="form-control"
                                name="quantities[]"
                                step="0.01"
                                min="0.01"
                                required
                            >
                        </div>

                        <div class="col-md-2 d-flex align-items-center">
                            <button
                                type="button"
                                class="btn btn-danger w-100 remove-ingredient"
                            >
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                @endif
            </div>

            <button
                type="button"
                id="add-ingredient"
                class="btn btn-sm btn-outline-mauve mt-2"
            >
                <i class="fas fa-plus me-1"></i>
                Add Ingredient
            </button>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <button
                    type="submit"
                    class="btn btn-mauve"
                >
                    <i class="fas fa-save me-2"></i>

                    {{ $isEdit ? 'Update Recipe' : 'Create Recipe' }}
                </button>

                <a
                    href="{{ route('recipes.index') }}"
                    class="btn btn-outline-secondary"
                >
                    Cancel
                </a>
            </div>

            @if($canDeleteRecipe)
                <button
                    type="submit"
                    form="delete-recipe-form"
                    class="btn btn-outline-danger"
                >
                    <i class="fas fa-trash me-1"></i>
                    Delete Recipe
                </button>
            @endif
        </div>
    </form>

    @if($canDeleteRecipe)
        <form
            id="delete-recipe-form"
            method="POST"
            action="{{ route('recipes.destroy', $recipe) }}"
            class="d-none"
            onsubmit="return confirm('Are you sure you want to delete this recipe?')"
        >
            @csrf
            @method('DELETE')
        </form>
    @endif
@endif

@if($canSaveRecipe)
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const isPremiumCheckbox =
            document.getElementById('is_premium');

        const compositionSection =
            document.getElementById('composition-section');

        const addComposition =
            document.getElementById('add-composition');

        const compositionContainer =
            document.getElementById('composition-container');

        const addIngredient =
            document.getElementById('add-ingredient');

        const ingredientsContainer =
            document.getElementById('ingredients-container');

        const speciesSelect =
            document.getElementById('species');

        const breedSizeWrapper =
            document.getElementById('breed-size-category-wrapper');

        const breedSizeSelect =
            document.getElementById('breed_size_category');

        const lifeStageSelect =
            document.getElementById('life_stage');

        function toggleComposition() {
            if (!isPremiumCheckbox || !compositionSection) {
                return;
            }

            if (isPremiumCheckbox.checked) {
                compositionSection.classList.remove('d-none');
            } else {
                compositionSection.classList.add('d-none');
            }
        }

        function toggleBreedSizeCategory() {
            if (
                !speciesSelect
                || !breedSizeWrapper
                || !breedSizeSelect
            ) {
                return;
            }

            if (speciesSelect.value === 'Dog') {
                breedSizeWrapper.classList.remove('d-none');
                breedSizeSelect.required = true;
            } else {
                breedSizeWrapper.classList.add('d-none');
                breedSizeSelect.value = '';
                breedSizeSelect.required = false;
            }
        }

        function updateLifeStageOptions() {
            if (!speciesSelect || !lifeStageSelect) {
                return;
            }

            const currentValue = lifeStageSelect.value;

            const options = speciesSelect.value === 'Dog'
                ? [
                    {
                        value: '',
                        text: '-- Select life stage --'
                    },
                    {
                        value: 'Puppy',
                        text: 'Puppy'
                    },
                    {
                        value: 'Adult',
                        text: 'Adult'
                    },
                    {
                        value: 'Senior',
                        text: 'Senior'
                    },
                    {
                        value: 'All',
                        text: 'All'
                    }
                ]
                : [
                    {
                        value: '',
                        text: '-- Select life stage --'
                    },
                    {
                        value: 'Kitten',
                        text: 'Kitten'
                    },
                    {
                        value: 'Adult',
                        text: 'Adult'
                    },
                    {
                        value: 'Senior',
                        text: 'Senior'
                    },
                    {
                        value: 'All',
                        text: 'All'
                    }
                ];

            lifeStageSelect.innerHTML = '';

            options.forEach(function (option) {
                const optionElement =
                    document.createElement('option');

                optionElement.value = option.value;
                optionElement.textContent = option.text;

                if (currentValue === option.value) {
                    optionElement.selected = true;
                }

                lifeStageSelect.appendChild(optionElement);
            });
        }

        function updateRemoveIngredientButtons() {
            if (!ingredientsContainer) {
                return;
            }

            const rows =
                ingredientsContainer.querySelectorAll('.ingredient-row');

            rows.forEach(function (row) {
                const button =
                    row.querySelector('.remove-ingredient');

                if (!button) {
                    return;
                }

                button.disabled = rows.length <= 1;
            });
        }

        function updateRemoveCompositionButtons() {
            if (!compositionContainer) {
                return;
            }

            const rows =
                compositionContainer.querySelectorAll('.composition-row');

            rows.forEach(function (row) {
                const button =
                    row.querySelector('.remove-composition');

                if (!button) {
                    return;
                }

                button.disabled = rows.length <= 1;
            });
        }

        toggleComposition();
        toggleBreedSizeCategory();
        updateLifeStageOptions();
        updateRemoveIngredientButtons();
        updateRemoveCompositionButtons();

        if (isPremiumCheckbox) {
            isPremiumCheckbox.addEventListener(
                'change',
                toggleComposition
            );
        }

        if (speciesSelect) {
            speciesSelect.addEventListener('change', function () {
                toggleBreedSizeCategory();
                updateLifeStageOptions();
            });
        }

        if (addComposition && compositionContainer) {
            addComposition.addEventListener('click', function () {
                const row = document.createElement('div');

                row.className =
                    'row mb-2 composition-row';

                row.innerHTML = `
                    <div class="col-md-5 mb-2 mb-md-0">
                        <input
                            type="text"
                            class="form-control"
                            name="composition_labels[]"
                            placeholder="Nutrient (Protein, Calcium, etc.)"
                        >
                    </div>

                    <div class="col-md-5 mb-2 mb-md-0">
                        <input
                            type="text"
                            class="form-control"
                            name="composition_values[]"
                            placeholder="Amount (120 g, 200 mg, etc.)"
                        >
                    </div>

                    <div class="col-md-2 d-flex align-items-center">
                        <button
                            type="button"
                            class="btn btn-danger w-100 remove-composition"
                        >
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                `;

                compositionContainer.appendChild(row);
                updateRemoveCompositionButtons();
            });
        }

        if (addIngredient && ingredientsContainer) {
            addIngredient.addEventListener('click', function () {
                const row = document.createElement('div');

                row.className =
                    'row mb-2 ingredient-row';

                row.innerHTML = `
                    <div class="col-md-6 mb-2 mb-md-0">
                        <select
                            class="form-control"
                            name="ingredients[]"
                            required
                        >
                            <option value="">
                                Select Ingredient
                            </option>

                            @foreach($ingredients as $ingredient)
                                <option value="{{ $ingredient->id }}">
                                    {{ addslashes($ingredient->name) }}
                                    ({{ addslashes($ingredient->unit) }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4 mb-2 mb-md-0">
                        <input
                            type="number"
                            class="form-control"
                            name="quantities[]"
                            step="0.01"
                            min="0.01"
                            required
                        >
                    </div>

                    <div class="col-md-2 d-flex align-items-center">
                        <button
                            type="button"
                            class="btn btn-danger w-100 remove-ingredient"
                        >
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                `;

                ingredientsContainer.appendChild(row);
                updateRemoveIngredientButtons();
            });
        }

        document.addEventListener('click', function (event) {
            const removeIngredientButton =
                event.target.closest('.remove-ingredient');

            if (removeIngredientButton) {
                const ingredientRows =
                    ingredientsContainer.querySelectorAll(
                        '.ingredient-row'
                    );

                if (ingredientRows.length > 1) {
                    const row =
                        removeIngredientButton.closest(
                            '.ingredient-row'
                        );

                    if (row) {
                        row.remove();
                    }

                    updateRemoveIngredientButtons();
                }
            }

            const removeCompositionButton =
                event.target.closest('.remove-composition');

            if (removeCompositionButton) {
                const compositionRows =
                    compositionContainer.querySelectorAll(
                        '.composition-row'
                    );

                if (compositionRows.length > 1) {
                    const row =
                        removeCompositionButton.closest(
                            '.composition-row'
                        );

                    if (row) {
                        row.remove();
                    }

                    updateRemoveCompositionButtons();
                }
            }
        });
    });
    </script>
@endif
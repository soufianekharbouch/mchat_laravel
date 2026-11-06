{{--
    Shared product form (create + edit).

    Variables:
      $product          Product (new or existing)
      $recipes          all recipes
      $selectedRecipes  [recipe_id => ['selected', 'max_quantity', 'is_default']]
--}}
@php
    /*
     * Recipe state: values sent back after a validation error,
     * otherwise the saved pack recipes.
     */
    $oldRecipes = old('recipes');

    $recipeState = $oldRecipes !== null
        ? collect($oldRecipes)->map(fn ($row) => [
            'selected' => !empty($row['selected']),
            'max_quantity' => $row['max_quantity'] ?? null,
            'is_default' => !empty($row['is_default']),
        ])->all()
        : $selectedRecipes;

    /*
     * Selected recipes first (in their saved order), then the others.
     */
    $selectedOrder = array_keys(array_filter($recipeState, fn ($row) => !empty($row['selected'])));

    $sortedRecipes = $recipes->sortBy(function ($recipe) use ($selectedOrder) {
        $position = array_search($recipe->id, $selectedOrder);

        return $position === false ? 100000 . '|' . $recipe->name : str_pad($position, 6, '0', STR_PAD_LEFT);
    })->values();

    $dateValue = fn ($value) => $value ? \Carbon\Carbon::parse($value)->format('Y-m-d\TH:i') : '';

    $species = old('species', $product->species ?? 'Cat');
@endphp

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1"><i class="fas fa-exclamation-triangle me-1"></i>Please fix the following:</div>
        <ul class="mb-0 small">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    {{-- ============================================================ --}}
    {{-- LEFT COLUMN                                                   --}}
    {{-- ============================================================ --}}
    <div class="col-lg-8">

        {{-- GENERAL --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2"><strong><i class="fas fa-box me-1"></i>General</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="code" class="form-label">Code *</label>
                        <input type="text" id="code" name="code"
                               class="form-control text-uppercase @error('code') is-invalid @enderror"
                               value="{{ old('code', $product->code) }}"
                               placeholder="CAT-REG-1M" maxlength="50" required>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Unique. Letters, numbers, - and _.</div>
                    </div>

                    <div class="col-md-4">
                        <label for="name" class="form-label">Name (English) *</label>
                        <input type="text" id="name" name="name"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $product->name) }}" maxlength="191" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label for="name_ar" class="form-label">Name (Arabic)</label>
                        <input type="text" id="name_ar" name="name_ar" dir="rtl"
                               class="form-control @error('name_ar') is-invalid @enderror"
                               value="{{ old('name_ar', $product->name_ar) }}" maxlength="191">
                        @error('name_ar')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label for="description" class="form-label">Description (English)</label>
                        <textarea id="description" name="description" rows="4"
                                  class="form-control @error('description') is-invalid @enderror">{{ old('description', $product->description) }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label for="description_ar" class="form-label">Description (Arabic)</label>
                        <textarea id="description_ar" name="description_ar" rows="4" dir="rtl"
                                  class="form-control @error('description_ar') is-invalid @enderror">{{ old('description_ar', $product->description_ar) }}</textarea>
                        @error('description_ar')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- PRODUCT PAGE OF THE APP --}}
        @php
            $features = (array) old('features', $product->exists ? $product->featuresList() : \App\Models\Product::DEFAULT_FEATURES);
            $contentRows = array_values((array) old('contents', $product->contents ?? []));
            $libraryUrls = collect($mediaLibrary ?? [])->pluck('url', 'path');
        @endphp
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2"><strong><i class="fas fa-mobile-alt me-1"></i>Product page of the app</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="subtitle" class="form-label">Subtitle (English)</label>
                        <input type="text" id="subtitle" name="subtitle" maxlength="191"
                               class="form-control @error('subtitle') is-invalid @enderror"
                               value="{{ old('subtitle', $product->subtitle) }}" placeholder="Try our fresh meals">
                    </div>
                    <div class="col-md-6">
                        <label for="subtitle_ar" class="form-label">Subtitle (Arabic)</label>
                        <input type="text" id="subtitle_ar" name="subtitle_ar" maxlength="191" dir="rtl"
                               class="form-control @error('subtitle_ar') is-invalid @enderror"
                               value="{{ old('subtitle_ar', $product->subtitle_ar) }}" placeholder="جرّب وجباتنا الطازجة">
                    </div>

                    <div class="col-md-4">
                        <label for="bags_count" class="form-label">Bags in the pack (badge)</label>
                        <input type="number" min="1" id="bags_count" name="bags_count"
                               class="form-control @error('bags_count') is-invalid @enderror"
                               value="{{ old('bags_count', $product->bags_count) }}" placeholder="= total recipes">
                        <div class="form-text">Badge "5 bags" on the photo. Empty = total recipes.</div>
                    </div>
                    <div class="col-md-4">
                        <label for="bag_weight_grams" class="form-label">Weight of one bag (g)</label>
                        <input type="number" min="1" id="bag_weight_grams" name="bag_weight_grams"
                               class="form-control @error('bag_weight_grams') is-invalid @enderror"
                               value="{{ old('bag_weight_grams', $product->bag_weight_grams) }}" placeholder="170">
                        <div class="form-text">Empty = not shown.</div>
                    </div>
                    <div class="col-md-4 d-flex align-items-center">
                        <div class="form-check form-switch mt-3">
                            <input type="hidden" name="free_delivery" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="free_delivery" name="free_delivery" value="1"
                                   @checked(old('free_delivery', $product->free_delivery ?? true))>
                            <label class="form-check-label" for="free_delivery">Free delivery</label>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label d-block">Qualities shown (icons)</label>
                        @foreach(\App\Models\Product::FEATURES as $key => $label)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="features[]" value="{{ $key }}" id="feature_{{ $key }}"
                                       @checked(in_array($key, $features, true))>
                                <label class="form-check-label" for="feature_{{ $key }}">{{ $label['en'] }} <span class="text-muted">· {{ $label['ar'] }}</span></label>
                            </div>
                        @endforeach
                        <div class="form-text">None ticked = Fresh, Natural, Keep chilled or frozen.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label d-block mb-1">What's inside the pack</label>
                        <div class="form-text mt-0 mb-2">One line per item (e.g. "Bags" × 5, "Flavour powder"). Empty lines are ignored.</div>
                        @error('contents')<div class="text-danger small mb-1">{{ $message }}</div>@enderror

                        @for($i = 0; $i < \App\Models\Product::MAX_CONTENTS; $i++)
                            @php
                                $row = $contentRows[$i] ?? [];
                                $rowImage = $row['image'] ?? '';
                                $rowImageUrl = $rowImage !== '' ? ($libraryUrls[$rowImage] ?? asset('storage/app/public/' . $rowImage)) : '';
                            @endphp
                            <div class="row g-2 align-items-center mb-2 content-row">
                                <div class="col-auto">
                                    <input type="hidden" name="contents[{{ $i }}][image]" id="content_image_{{ $i }}" value="{{ $rowImage }}">
                                    <button type="button" class="btn btn-light border content-image-button p-0"
                                            data-library-mode="content" data-content-index="{{ $i }}" title="Choose the image">
                                        <img id="content_preview_{{ $i }}" src="{{ $rowImageUrl }}" alt="" class="{{ $rowImageUrl ? '' : 'd-none' }}">
                                        <i class="fas fa-image text-muted {{ $rowImageUrl ? 'd-none' : '' }}" id="content_icon_{{ $i }}"></i>
                                    </button>
                                </div>
                                <div class="col">
                                    <input type="text" name="contents[{{ $i }}][label]" class="form-control form-control-sm" maxlength="100"
                                           value="{{ $row['label'] ?? '' }}" placeholder="Label (English) e.g. Bags">
                                </div>
                                <div class="col">
                                    <input type="text" name="contents[{{ $i }}][label_ar]" class="form-control form-control-sm" maxlength="100" dir="rtl"
                                           value="{{ $row['label_ar'] ?? '' }}" placeholder="الاسم (عربي) مثال: أكياس">
                                </div>
                                <div class="col-2">
                                    <input type="number" min="1" name="contents[{{ $i }}][quantity]" class="form-control form-control-sm"
                                           value="{{ $row['quantity'] ?? '' }}" placeholder="Qty">
                                </div>
                                <div class="col-auto">
                                    <button type="button" class="btn btn-sm btn-outline-secondary content-clear" data-content-index="{{ $i }}" title="Clear the line">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        @endfor
                    </div>

                    <div class="col-12">
                        <div class="form-text">
                            The blocks "Ingredients", "How to serve", "Storage", "Delivery" of the app come from the
                            <strong>description</strong>: one block per <code>## Title</code> line, then its text
                            (lines starting with <code>-</code> = list, <code>**bold**</code>).
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- CLASSIFICATION --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2"><strong><i class="fas fa-tags me-1"></i>Classification</strong></div>
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="species" class="form-label">Species *</label>
                        <select id="species" name="species" class="form-select @error('species') is-invalid @enderror" required>
                            @foreach(\App\Models\Product::SPECIES as $value)
                                <option value="{{ $value }}" @selected($species === $value)>{{ $value }}</option>
                            @endforeach
                        </select>
                        @error('species')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-3" id="breedSizeWrap">
                        <label for="breed_size_category" class="form-label">Dog size</label>
                        <select id="breed_size_category" name="breed_size_category"
                                class="form-select @error('breed_size_category') is-invalid @enderror">
                            <option value="">All sizes</option>
                            @foreach(\App\Models\Product::BREED_SIZES as $value)
                                <option value="{{ $value }}" @selected(old('breed_size_category', $product->breed_size_category) === $value)>{{ $value }}</option>
                            @endforeach
                        </select>
                        @error('breed_size_category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-3">
                        <label for="product_type" class="form-label">Product type *</label>
                        <select id="product_type" name="product_type" class="form-select @error('product_type') is-invalid @enderror" required>
                            <option value="regular" @selected(old('product_type', $product->product_type) === 'regular')>Regular</option>
                            <option value="premium" @selected(old('product_type', $product->product_type) === 'premium')>Premium</option>
                        </select>
                        @error('product_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-3">
                        <input type="hidden" name="is_trial" value="0">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_trial" name="is_trial" value="1"
                                   @checked(old('is_trial', $product->is_trial))>
                            <label class="form-check-label" for="is_trial">Trial pack</label>
                        </div>
                    </div>

                    {{-- AGES --}}
                    @php
                        $selectedStages = old('life_stages', $product->lifeStagesList());
                    @endphp
                    <div class="col-12">
                        <label class="form-label mb-1">Pet ages</label>
                        <div class="d-flex flex-wrap gap-3" id="lifeStagesWrap">
                            @foreach(\App\Models\Product::LIFE_STAGES as $stage)
                                <div class="form-check life-stage-option"
                                     data-species="{{ $stage === 'Kitten' ? 'Cat' : ($stage === 'Puppy' ? 'Dog' : '') }}">
                                    <input class="form-check-input" type="checkbox" name="life_stages[]"
                                           id="life_stage_{{ $stage }}" value="{{ $stage }}"
                                           @checked(in_array($stage, (array) $selectedStages, true))>
                                    <label class="form-check-label" for="life_stage_{{ $stage }}">{{ $stage }}</label>
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text">
                            The app only shows this pack for pets of the ticked ages.
                            Nothing ticked = all ages.
                        </div>
                        @error('life_stages')<div class="text-danger small">{{ $message }}</div>@enderror
                        @error('life_stages.*')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- DURATION & DELIVERIES --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2"><strong><i class="fas fa-truck me-1"></i>Duration &amp; deliveries</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Duration *</label>
                        <div class="input-group">
                            <input type="number" min="1" max="1000" name="duration_value" id="duration_value"
                                   class="form-control @error('duration_value') is-invalid @enderror"
                                   value="{{ old('duration_value', $product->duration_value) }}" required>
                            <select name="duration_unit" id="duration_unit" class="form-select @error('duration_unit') is-invalid @enderror">
                                @foreach(\App\Models\Product::DURATION_UNITS as $unit)
                                    <option value="{{ $unit }}" @selected(old('duration_unit', $product->duration_unit) === $unit)>{{ ucfirst($unit) }}(s)</option>
                                @endforeach
                            </select>
                        </div>
                        @error('duration_value')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label for="total_recipes" class="form-label">Total recipes in the pack *</label>
                        <input type="number" min="1" max="10000" id="total_recipes" name="total_recipes"
                               class="form-control @error('total_recipes') is-invalid @enderror"
                               value="{{ old('total_recipes', $product->total_recipes) }}" required>
                        @error('total_recipes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label for="min_days_between_deliveries" class="form-label">Min days between deliveries</label>
                        <input type="number" min="0" max="365" id="min_days_between_deliveries" name="min_days_between_deliveries"
                               class="form-control @error('min_days_between_deliveries') is-invalid @enderror"
                               value="{{ old('min_days_between_deliveries', $product->min_days_between_deliveries) }}"
                               placeholder="No constraint">
                        @error('min_days_between_deliveries')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="min_deliveries" class="form-label">Min deliveries *</label>
                        <input type="number" min="1" id="min_deliveries" name="min_deliveries"
                               class="form-control @error('min_deliveries') is-invalid @enderror"
                               value="{{ old('min_deliveries', $product->min_deliveries) }}" required>
                        @error('min_deliveries')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="max_deliveries" class="form-label">Max deliveries *</label>
                        <input type="number" min="1" id="max_deliveries" name="max_deliveries"
                               class="form-control @error('max_deliveries') is-invalid @enderror"
                               value="{{ old('max_deliveries', $product->max_deliveries) }}" required>
                        @error('max_deliveries')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="min_recipes_per_delivery" class="form-label">Min recipes / delivery *</label>
                        <input type="number" min="1" id="min_recipes_per_delivery" name="min_recipes_per_delivery"
                               class="form-control @error('min_recipes_per_delivery') is-invalid @enderror"
                               value="{{ old('min_recipes_per_delivery', $product->min_recipes_per_delivery) }}" required>
                        @error('min_recipes_per_delivery')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="max_recipes_per_delivery" class="form-label">Max recipes / delivery *</label>
                        <input type="number" min="1" id="max_recipes_per_delivery" name="max_recipes_per_delivery"
                               class="form-control @error('max_recipes_per_delivery') is-invalid @enderror"
                               value="{{ old('max_recipes_per_delivery', $product->max_recipes_per_delivery) }}" required>
                        @error('max_recipes_per_delivery')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <div id="deliveryCheck" class="delivery-check small"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- RECIPES --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2 d-flex justify-content-between align-items-center">
                <strong><i class="fas fa-utensils me-1"></i>Recipes *</strong>
                <span class="badge bg-mauve" id="recipesSelectedCount">0 selected</span>
            </div>
            <div class="card-body">
                @error('recipes')
                    <div class="alert alert-danger py-2 small">{{ $message }}</div>
                @enderror

                <div class="row g-2 mb-2">
                    <div class="col-md-7">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" id="recipeSearch" placeholder="Search by recipe name or code...">
                            <button class="btn btn-outline-secondary" type="button" id="recipeSearchClear">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-5 d-flex align-items-center gap-3">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="recipeSelectedOnly">
                            <label class="form-check-label small" for="recipeSelectedOnly">Selected only</label>
                        </div>
                        <button type="button" class="btn btn-link btn-sm p-0" id="recipeSelectVisible">Select visible</button>
                        <button type="button" class="btn btn-link btn-sm p-0 text-danger" id="recipeClearAll">Clear</button>
                    </div>
                </div>

                <div class="small text-muted mb-2" id="recipeSpeciesHint"></div>

                <div class="recipe-list" id="recipeList">
                    @foreach($sortedRecipes as $recipe)
                        @php
                            $state = $recipeState[$recipe->id] ?? [];
                            $isSelected = !empty($state['selected']);
                            $recipeSpecies = $recipe->species ?? 'Cat';
                        @endphp

                        <div class="recipe-row {{ $isSelected ? 'is-selected' : '' }}"
                             data-recipe-name="{{ strtolower($recipe->name) }}"
                             data-recipe-code="{{ strtolower($recipe->code ?? '') }}"
                             data-recipe-species="{{ $recipeSpecies }}"
                             data-recipe-breed-size="{{ $recipe->breed_size_category ?? '' }}">

                            <div class="form-check mb-0 recipe-main">
                                <input type="checkbox"
                                       class="form-check-input recipe-select"
                                       id="recipe_{{ $recipe->id }}"
                                       name="recipes[{{ $recipe->id }}][selected]"
                                       value="1"
                                       @checked($isSelected)>
                                <label class="form-check-label" for="recipe_{{ $recipe->id }}">
                                    <span class="fw-semibold">{{ $recipe->name }}</span>
                                    @if($recipe->code)
                                        <span class="text-muted small">({{ $recipe->code }})</span>
                                    @endif
                                    <span class="d-block small text-muted">
                                        {{ $recipeSpecies }}
                                        @if($recipe->breed_size_category) · {{ $recipe->breed_size_category }} @endif
                                        @if($recipe->life_stage ?? null) · {{ $recipe->life_stage }} @endif
                                        @if(!empty($recipe->is_premium))
                                            <span class="badge bg-warning text-dark ms-1">Premium</span>
                                        @endif
                                    </span>
                                </label>
                            </div>

                            <div class="recipe-options">
                                <div class="input-group input-group-sm recipe-max">
                                    <span class="input-group-text">Max</span>
                                    <input type="number" min="1"
                                           class="form-control"
                                           name="recipes[{{ $recipe->id }}][max_quantity]"
                                           value="{{ $state['max_quantity'] ?? '' }}"
                                           placeholder="Free"
                                           title="Max quantity of this recipe in the pack (empty = free)">
                                </div>

                                <div class="form-check mb-0">
                                    <input type="checkbox" class="form-check-input"
                                           id="recipe_default_{{ $recipe->id }}"
                                           name="recipes[{{ $recipe->id }}][is_default]"
                                           value="1"
                                           @checked(!empty($state['is_default']))>
                                    <label class="form-check-label small" for="recipe_default_{{ $recipe->id }}"
                                           title="Pre-selected for the customer">Default</label>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div id="recipeNoResults" class="text-center text-muted small py-3" style="display:none;">
                    No recipes found.
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- RIGHT COLUMN                                                  --}}
    {{-- ============================================================ --}}
    <div class="col-lg-4">

        {{-- PRICE --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2"><strong><i class="fas fa-coins me-1"></i>Price</strong></div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-8">
                        <label for="price" class="form-label">Price *</label>
                        <input type="number" step="0.001" min="0" id="price" name="price"
                               class="form-control @error('price') is-invalid @enderror"
                               value="{{ old('price', $product->price) }}" required>
                        @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-4">
                        <label for="currency" class="form-label">Currency</label>
                        <input type="text" id="currency" name="currency" maxlength="3"
                               class="form-control text-uppercase @error('currency') is-invalid @enderror"
                               value="{{ old('currency', $product->currency ?? 'KWD') }}" required>
                    </div>

                    <div class="col-12">
                        <label for="promo_price" class="form-label">Promotional price</label>
                        <input type="number" step="0.001" min="0" id="promo_price" name="promo_price"
                               class="form-control @error('promo_price') is-invalid @enderror"
                               value="{{ old('promo_price', $product->promo_price) }}" placeholder="No promotion">
                        @error('promo_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12 promo-dates">
                        <label class="form-label small mb-1">Promotion from / to (optional)</label>
                        <input type="datetime-local" name="promo_starts_at"
                               class="form-control form-control-sm mb-1 @error('promo_starts_at') is-invalid @enderror"
                               value="{{ old('promo_starts_at', $dateValue($product->promo_starts_at)) }}">
                        <input type="datetime-local" name="promo_ends_at"
                               class="form-control form-control-sm @error('promo_ends_at') is-invalid @enderror"
                               value="{{ old('promo_ends_at', $dateValue($product->promo_ends_at)) }}">
                        @error('promo_ends_at')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12" id="trialOfferWrap">
                        <label for="trial_offer_price" class="form-label">
                            Price during a trial
                            <i class="fas fa-info-circle text-muted"
                               title="Special price shown to customers whose pet has a trial pack still running. Usable once per trial."></i>
                        </label>
                        <input type="number" step="0.001" min="0" id="trial_offer_price" name="trial_offer_price"
                               class="form-control @error('trial_offer_price') is-invalid @enderror"
                               value="{{ old('trial_offer_price', $product->trial_offer_price) }}" placeholder="No trial offer">
                        @error('trial_offer_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            Offered to a pet that bought a trial pack, until the end of that trial.
                        </div>
                    </div>
                </div>

                <div class="price-summary mt-3" id="priceSummary"></div>
            </div>
        </div>

        {{-- IMAGE --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2"><strong><i class="fas fa-image me-1"></i>Image</strong></div>
            <div class="card-body">
                <div class="text-center mb-2">
                    <img id="imagePreview"
                         src="{{ $product->image_url ?? '' }}"
                         alt=""
                         class="product-image-preview {{ $product->image_url ? '' : 'd-none' }}">
                </div>

                {{-- Main image: chosen in the library (upload a new one there) --}}
                <input type="hidden" name="image_existing" id="image_existing" value="{{ old('image_existing') }}">
                <button type="button" class="btn btn-sm btn-outline-primary w-100" data-library-mode="cover">
                    <i class="fas fa-images me-1"></i>{{ $product->image ? 'Change the image' : 'Choose an image' }}
                </button>
                @error('image')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                <div class="form-text">From the library, or upload a new one there (JPG, PNG, WebP, max 4 MB).</div>

                @if($product->image)
                    <div class="form-check mt-2">
                        <input type="hidden" name="remove_image" value="0">
                        <input class="form-check-input" type="checkbox" id="remove_image" name="remove_image" value="1">
                        <label class="form-check-label small" for="remove_image">Remove current image</label>
                    </div>
                @endif
            </div>
        </div>

        {{-- GALLERY: images + videos (carousel of the app) --}}
        @php
            $galleryItems = $product->exists ? $product->media : collect();
            $maxMedia = \App\Models\ProductMedia::MAX_PER_PRODUCT;
            $removedMedia = array_map('intval', (array) old('media_remove', []));
        @endphp
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2 d-flex justify-content-between align-items-center">
                <strong><i class="fas fa-photo-video me-1"></i>Gallery</strong>
                <span class="badge bg-mauve" id="mediaCount">{{ $galleryItems->count() }} / {{ $maxMedia }}</span>
            </div>
            <div class="card-body">
                <div class="form-text mt-0 mb-2">
                    Shown in the app after the main image, as a carousel. Videos play in the carousel.
                </div>

                @error('media_files')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
                @error('media_files.*')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror

                <input type="hidden" name="media_order" id="media_order" value="{{ $galleryItems->pluck('id')->implode(',') }}">

                {{-- Saved items: order + remove --}}
                <div id="mediaList" class="media-list">
                    @foreach($galleryItems as $item)
                        @php $isRemoved = in_array($item->id, $removedMedia, true); @endphp
                        <div class="media-item {{ $isRemoved ? 'is-removed' : '' }}" data-id="{{ $item->id }}">
                            <div class="media-thumb">
                                @if($item->isVideo())
                                    <video src="{{ $item->url }}#t=0.5" preload="metadata" muted playsinline></video>
                                    <span class="media-play"><i class="fas fa-play"></i></span>
                                @else
                                    <img src="{{ $item->url }}" alt="">
                                @endif
                            </div>

                            <div class="media-info small">
                                <span class="badge {{ $item->isVideo() ? 'bg-dark' : 'bg-secondary' }}">
                                    <i class="fas fa-{{ $item->isVideo() ? 'video' : 'image' }} me-1"></i>{{ $item->isVideo() ? 'Video' : 'Image' }}
                                </span>
                                <div class="text-muted">{{ $item->sizeLabel() }}</div>
                            </div>

                            <div class="media-actions">
                                <button type="button" class="btn btn-sm btn-light media-up" title="Move up"><i class="fas fa-arrow-up"></i></button>
                                <button type="button" class="btn btn-sm btn-light media-down" title="Move down"><i class="fas fa-arrow-down"></i></button>
                                <label class="btn btn-sm btn-outline-danger mb-0" title="Remove">
                                    <input type="checkbox" class="d-none media-remove" name="media_remove[]" value="{{ $item->id }}" @checked($isRemoved)>
                                    <i class="fas fa-trash"></i>
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Added from the library (upload new files there, one by one) --}}
                <button type="button" class="btn btn-sm btn-outline-primary w-100 mt-2" data-library-mode="gallery">
                    <i class="fas fa-images me-1"></i>Add images / videos
                </button>
                <div class="form-text">
                    From the library, or upload new files there one by one (images max 4 MB · videos MP4 / MOV / WebM
                    max 50 MB). Max {{ $maxMedia }} per product, added at the end.
                </div>
                <div id="mediaLibraryChosen" class="media-new">
                    @foreach((array) old('media_existing', []) as $path)
                        <input type="hidden" name="media_existing[]" value="{{ $path }}">
                    @endforeach
                </div>
            </div>
        </div>

        {{-- DISPLAY & LIMITS --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2"><strong><i class="fas fa-sliders-h me-1"></i>Display &amp; limits</strong></div>
            <div class="card-body">
                <input type="hidden" name="is_active" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                           @checked(old('is_active', $product->is_active ?? true))>
                    <label class="form-check-label" for="is_active">Active (visible in the app)</label>
                </div>

                <label for="sort_order" class="form-label">Display order</label>
                <input type="number" min="0" id="sort_order" name="sort_order"
                       class="form-control mb-3 @error('sort_order') is-invalid @enderror"
                       value="{{ old('sort_order', $product->sort_order ?? 0) }}">

                <label for="max_purchases_per_customer" class="form-label">Max purchases per customer</label>
                <input type="number" min="1" id="max_purchases_per_customer" name="max_purchases_per_customer"
                       class="form-control @error('max_purchases_per_customer') is-invalid @enderror"
                       value="{{ old('max_purchases_per_customer', $product->max_purchases_per_customer) }}"
                       placeholder="Unlimited">
                @error('max_purchases_per_customer')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">e.g. 1 for a trial pack.</div>
            </div>
        </div>

        {{-- SHOPIFY --}}
        @php
            $shopifyProducts = $shopifyCatalog['products'] ?? [];
            $shopifyError = $shopifyCatalog['error'] ?? null;
            $currentShopifyProduct = (string) old('shopify_product_id', $product->shopify_product_id);
            $currentShopifyVariant = (string) old('shopify_variant_id', $product->shopify_variant_id);

            // Saved variant still in the store?
            $variantFound = $currentShopifyVariant === '' || collect($shopifyProducts)
                ->contains(fn ($p) => collect($p['variants'])->contains('id', $currentShopifyVariant));
        @endphp
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-yellow py-2 d-flex justify-content-between align-items-center">
                <strong><i class="fab fa-shopify me-1"></i>Shopify (optional)</strong>
                <a href="{{ request()->fullUrlWithQuery(['refresh_shopify' => 1]) }}"
                   class="small text-decoration-none" title="Reload products from the store">
                    <i class="fas fa-sync-alt"></i> Refresh
                </a>
            </div>
            <div class="card-body">
                <input type="hidden" id="shopify_product_id" name="shopify_product_id" value="{{ $currentShopifyProduct }}">
                <input type="hidden" id="shopify_variant_id" name="shopify_variant_id" value="{{ $currentShopifyVariant }}">

                @if($shopifyError)
                    <div class="alert alert-warning py-2 small mb-2">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Unable to load the Shopify products: {{ $shopifyError }}
                    </div>
                @endif

                @if(count($shopifyProducts))
                    <input type="text" id="shopifySearch" class="form-control form-control-sm mb-2"
                           placeholder="Search a product, variant or SKU...">

                    <select id="shopifyVariantSelect" class="form-select form-select-sm" size="8">
                        <option value="" data-product="" @selected($currentShopifyVariant === '')>— Not linked to Shopify —</option>

                        @unless($variantFound)
                            <option value="{{ $currentShopifyVariant }}" data-product="{{ $currentShopifyProduct }}" selected>
                                Saved variant #{{ $currentShopifyVariant }} (not found in the store)
                            </option>
                        @endunless

                        @foreach($shopifyProducts as $shopifyProduct)
                            <optgroup label="{{ $shopifyProduct['title'] }}{{ $shopifyProduct['status'] && $shopifyProduct['status'] !== 'ACTIVE' ? ' (' . strtolower($shopifyProduct['status']) . ')' : '' }}">
                                @foreach($shopifyProduct['variants'] as $variant)
                                    @php
                                        $variantTitle = $variant['title'] === 'Default Title' ? $shopifyProduct['title'] : $variant['title'];
                                    @endphp
                                    <option value="{{ $variant['id'] }}"
                                            data-product="{{ $shopifyProduct['id'] }}"
                                            data-product-title="{{ $shopifyProduct['title'] }}"
                                            data-variant-title="{{ $variantTitle }}"
                                            data-sku="{{ $variant['sku'] }}"
                                            data-price="{{ $variant['price'] }}"
                                            data-image="{{ $shopifyProduct['image'] }}"
                                            data-search="{{ strtolower($shopifyProduct['title'] . ' ' . $variant['title'] . ' ' . $variant['sku']) }}"
                                            @selected($currentShopifyVariant === $variant['id'])>
                                        {{ $variantTitle }}@if($variant['sku']) · {{ $variant['sku'] }}@endif @if($variant['price'] !== null) · {{ $variant['price'] }}@endif
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>

                    <div id="shopifySelected" class="shopify-selected mt-2"></div>
                @else
                    {{-- Shopify unreachable: IDs typed by hand --}}
                    <label for="shopify_product_id_manual" class="form-label small">Product ID</label>
                    <input type="text" id="shopify_product_id_manual" maxlength="100"
                           class="form-control form-control-sm mb-2" value="{{ $currentShopifyProduct }}"
                           oninput="document.getElementById('shopify_product_id').value = this.value">

                    <label for="shopify_variant_id_manual" class="form-label small">Variant ID</label>
                    <input type="text" id="shopify_variant_id_manual" maxlength="100"
                           class="form-control form-control-sm" value="{{ $currentShopifyVariant }}"
                           oninput="document.getElementById('shopify_variant_id').value = this.value">
                @endif
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mt-2">
    @if($product->exists && auth()->user()?->hasPermission('products.create'))
        {{-- Copy of the SAVED product (unsaved changes of this page are not copied) --}}
        <button type="submit" class="btn btn-outline-dark me-auto"
                formaction="{{ route('products.duplicate', $product) }}" formnovalidate
                onclick="return confirm('Duplicate this product? The copy uses the SAVED version, it is inactive and its code ends with -COPY.');">
            <i class="fas fa-copy me-1"></i>Duplicate
        </button>
    @endif

    <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-mauve">
        <i class="fas fa-save me-1"></i>{{ $product->exists ? 'Save changes' : 'Create product' }}
    </button>
</div>

{{-- MEDIA LIBRARY: images / videos already uploaded for the products --}}
<div class="modal fade" id="mediaLibraryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="mediaLibraryTitle"><i class="fas fa-images me-1"></i>Media library</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                {{-- Upload a new file (one by one): goes into the library, selected at once --}}
                <div class="lib-upload mb-3">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <label class="btn btn-mauve btn-sm mb-0">
                            <i class="fas fa-cloud-upload-alt me-1"></i>Upload a file
                            <input type="file" id="libUploadInput" class="d-none"
                                   accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm,video/x-m4v">
                        </label>
                        <span class="small text-muted" id="libUploadHint">Images JPG / PNG / WebP max 4 MB · videos MP4 / MOV / WebM max 50 MB</span>
                    </div>
                    @php $serverLimitMb = round(($serverUploadLimitKb ?? 51200) / 1024, 1); @endphp
                    @if($serverLimitMb < 50)
                        <div class="alert alert-warning py-1 px-2 small mt-2 mb-0">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            This server accepts files up to <b>{{ $serverLimitMb }} MB</b> only (PHP upload_max_filesize / post_max_size).
                            Raise them to 64M in cPanel to upload videos up to 50 MB.
                        </div>
                    @endif
                    <div class="progress mt-2 d-none" id="libUploadProgressWrap" style="height: 6px;">
                        <div class="progress-bar bg-mauve" id="libUploadProgress" style="width: 0%"></div>
                    </div>
                    <div class="small mt-1" id="libUploadStatus"></div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <input type="text" class="form-control form-control-sm" id="libSearch" placeholder="Search by product code or file name...">
                    </div>
                    <div class="col-md-6 d-flex gap-2" id="libTypeFilter">
                        <button type="button" class="btn btn-sm btn-mauve" data-type="">All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-type="image"><i class="fas fa-image me-1"></i>Images</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-type="video"><i class="fas fa-video me-1"></i>Videos</button>
                    </div>
                </div>
                <div id="libGrid" class="lib-grid"></div>
                <div id="libEmpty" class="text-center text-muted py-4" style="display:none;">No media found.</div>
            </div>
            <div class="modal-footer py-2">
                <span class="small text-muted me-auto" id="libSelectedInfo"></span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-mauve" id="libConfirm"><i class="fas fa-check me-1"></i>Use</button>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="mediaLibraryData">{!! json_encode($mediaLibrary ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

<style>
.content-image-button { width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.content-image-button img { width: 100%; height: 100%; object-fit: cover; }
.lib-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
.lib-item { position: relative; border: 2px solid transparent; border-radius: 10px; overflow: hidden; cursor: pointer; background: #f6f6f6; }
.lib-item:hover { border-color: rgba(108,92,231,.35); }
.lib-item.is-selected { border-color: #6c5ce7; box-shadow: 0 0 0 2px rgba(108,92,231,.25); }
.lib-item .lib-thumb { position: relative; height: 110px; background: #222; }
.lib-item .lib-thumb img, .lib-item .lib-thumb video { width: 100%; height: 100%; object-fit: cover; }
.lib-item .lib-check {
    position: absolute; top: 6px; right: 6px; width: 22px; height: 22px; border-radius: 50%;
    background: rgba(255,255,255,.85); display: flex; align-items: center; justify-content: center; font-size: 12px; color: transparent;
}
.lib-item.is-selected .lib-check { background: #6c5ce7; color: #fff; }
.lib-item .lib-label { padding: 4px 6px; font-size: 11px; color: #555; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.media-new-item .lib-remove {
    position: absolute; top: 2px; right: 2px; width: 18px; height: 18px; border-radius: 50%; border: 0;
    background: rgba(0,0,0,.6); color: #fff; font-size: 10px; line-height: 18px; padding: 0;
}
.media-new-item { position: relative; }
.media-new-item .badge-library { font-size: 10px; }

.media-list { display: flex; flex-direction: column; gap: 8px; }
.media-item {
    display: flex; align-items: center; gap: 10px;
    padding: 6px; border: 1px solid rgba(0,0,0,0.08); border-radius: 10px; background: #fff;
}
.media-item.is-removed { opacity: .4; background: #fff5f5; }
.media-item.is-removed .media-thumb { filter: grayscale(1); }
.media-thumb { position: relative; width: 64px; height: 64px; flex-shrink: 0; border-radius: 8px; overflow: hidden; background: #222; }
.media-thumb img, .media-thumb video { width: 100%; height: 100%; object-fit: cover; }
.media-play {
    position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 18px; text-shadow: 0 1px 4px rgba(0,0,0,.6);
}
.media-info { flex: 1; min-width: 0; }
.media-actions { display: flex; gap: 4px; }
.media-actions label:has(input:checked) { background: #dc3545; color: #fff; }
.media-new { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.media-new .media-new-item { width: 72px; text-align: center; font-size: 11px; color: #666; }
.media-new .media-new-item .media-thumb { width: 72px; height: 72px; }
.media-new .media-new-item.is-error .media-thumb { outline: 2px solid #dc3545; }
.recipe-list {
    max-height: 520px;
    overflow-y: auto;
    border: 1px solid rgba(0,0,0,0.08);
    border-radius: 10px;
}
.recipe-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-bottom: 1px solid rgba(0,0,0,0.06);
}
.recipe-row:last-child { border-bottom: 0; }
.recipe-row.is-selected { background: rgba(108, 92, 231, 0.06); }
.recipe-row .recipe-options { display: none; align-items: center; gap: 12px; }
.recipe-row.is-selected .recipe-options { display: flex; }
.recipe-max { width: 130px; }
.recipe-main { flex: 1; min-width: 0; }

.delivery-check {
    border-radius: 8px;
    padding: 8px 12px;
    border: 1px solid rgba(0,0,0,0.08);
    background: #fafafa;
}
.delivery-check.is-ok { border-color: rgba(34,197,94,0.35); background: rgba(34,197,94,0.06); }
.delivery-check.is-error { border-color: rgba(220,53,69,0.35); background: rgba(220,53,69,0.06); color: #842029; }

.price-summary {
    font-size: .85rem;
    border-radius: 8px;
    padding: 8px 12px;
    background: #fafafa;
    border: 1px solid rgba(0,0,0,0.06);
}
.shopify-selected {
    font-size: .85rem;
    border-radius: 8px;
    padding: 8px 10px;
    background: #fafafa;
    border: 1px solid rgba(0,0,0,0.06);
    display: flex;
    gap: 10px;
    align-items: center;
}
.shopify-selected:empty { display: none; }
.shopify-selected img { width: 42px; height: 42px; object-fit: cover; border-radius: 6px; }
#shopifyVariantSelect optgroup { font-weight: 600; }
#shopifyVariantSelect option { font-weight: 400; padding: 3px 6px; }
.product-image-preview {
    max-width: 100%;
    max-height: 180px;
    border-radius: 12px;
    object-fit: cover;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var $ = function (id) { return document.getElementById(id); };
    var num = function (id) { var v = parseFloat(($(id) || {}).value); return isNaN(v) ? 0 : v; };

    /*
    |--------------------------------------------------------------------------
    | SPECIES / DOG SIZE
    |--------------------------------------------------------------------------
    */
    var speciesSelect = $('species');
    var breedSizeSelect = $('breed_size_category');
    var breedSizeWrap = $('breedSizeWrap');

    function toggleBreedSize() {
        var isDog = speciesSelect.value === 'Dog';
        breedSizeWrap.style.display = isDog ? '' : 'none';
        if (!isDog) breedSizeSelect.value = '';
    }

    /*
    |--------------------------------------------------------------------------
    | RECIPES: filter by species / dog size / search
    |--------------------------------------------------------------------------
    */
    var rows = Array.prototype.slice.call(document.querySelectorAll('.recipe-row'));
    var searchInput = $('recipeSearch');
    var selectedOnly = $('recipeSelectedOnly');

    function recipeMatchesPack(row) {
        var species = row.getAttribute('data-recipe-species');
        if (species !== speciesSelect.value) return false;

        // Dog pack for a given size: recipes for that size or for all sizes
        var packSize = breedSizeSelect.value;
        var recipeSize = row.getAttribute('data-recipe-breed-size');
        if (speciesSelect.value === 'Dog' && packSize && recipeSize && recipeSize !== packSize) return false;

        return true;
    }

    function filterRecipes() {
        var q = (searchInput.value || '').trim().toLowerCase();
        var shown = 0;

        rows.forEach(function (row) {
            var checkbox = row.querySelector('.recipe-select');
            var matchesPack = recipeMatchesPack(row);

            // A recipe of another species can never stay in the pack
            if (!matchesPack && checkbox.checked) {
                checkbox.checked = false;
                row.classList.remove('is-selected');
            }

            var hay = row.getAttribute('data-recipe-name') + ' ' + row.getAttribute('data-recipe-code');
            var visible = matchesPack
                && (!q || hay.indexOf(q) !== -1)
                && (!selectedOnly.checked || checkbox.checked);

            row.style.display = visible ? '' : 'none';
            if (visible) shown++;
        });

        $('recipeNoResults').style.display = shown === 0 ? '' : 'none';
        $('recipeSpeciesHint').textContent = 'Showing ' + speciesSelect.value + ' recipes'
            + (speciesSelect.value === 'Dog' && breedSizeSelect.value ? ' (' + breedSizeSelect.value + ' + all sizes)' : '')
            + '.';

        updateSelectedCount();
        updateDeliveryCheck();
    }

    function updateSelectedCount() {
        var count = rows.filter(function (row) { return row.querySelector('.recipe-select').checked; }).length;
        $('recipesSelectedCount').textContent = count + ' selected';
    }

    rows.forEach(function (row) {
        row.querySelector('.recipe-select').addEventListener('change', function () {
            row.classList.toggle('is-selected', this.checked);
            updateSelectedCount();
            updateDeliveryCheck();
        });
    });

    searchInput.addEventListener('input', filterRecipes);
    selectedOnly.addEventListener('change', filterRecipes);

    $('recipeSearchClear').addEventListener('click', function () {
        searchInput.value = '';
        filterRecipes();
    });

    $('recipeSelectVisible').addEventListener('click', function () {
        rows.forEach(function (row) {
            if (row.style.display === 'none') return;
            row.querySelector('.recipe-select').checked = true;
            row.classList.add('is-selected');
        });
        updateSelectedCount();
        updateDeliveryCheck();
    });

    $('recipeClearAll').addEventListener('click', function () {
        if (!confirm('Unselect all recipes?')) return;
        rows.forEach(function (row) {
            row.querySelector('.recipe-select').checked = false;
            row.classList.remove('is-selected');
        });
        filterRecipes();
    });

    speciesSelect.addEventListener('change', function () {
        toggleBreedSize();
        filterRecipes();
    });
    breedSizeSelect.addEventListener('change', filterRecipes);

    /*
    |--------------------------------------------------------------------------
    | DELIVERY RULES CHECK (same rules as the server)
    |--------------------------------------------------------------------------
    */
    function updateDeliveryCheck() {
        var box = $('deliveryCheck');
        var total = num('total_recipes');
        var minD = num('min_deliveries'), maxD = num('max_deliveries');
        var minR = num('min_recipes_per_delivery'), maxR = num('max_recipes_per_delivery');

        if (!total || !minD || !maxD || !minR || !maxR) {
            box.className = 'delivery-check small';
            box.textContent = 'Fill the total recipes and the delivery limits.';
            return;
        }

        var problems = [];
        if (maxD < minD) problems.push('Max deliveries is lower than min deliveries.');
        if (maxR < minR) problems.push('Max recipes per delivery is lower than the minimum.');
        if (maxR > total) problems.push('Max recipes per delivery is higher than the total recipes.');
        if (maxD * maxR < total) problems.push(maxD + ' × ' + maxR + ' = ' + (maxD * maxR) + ' recipes max: not enough for ' + total + '.');
        if (minD * minR > total) problems.push(minD + ' × ' + minR + ' = ' + (minD * minR) + ' recipes min: more than ' + total + '.');

        // Capacity of selected recipes when all have a max
        var selected = rows.filter(function (row) { return row.querySelector('.recipe-select').checked; });
        var allLimited = selected.length > 0 && selected.every(function (row) {
            return row.querySelector('input[name$="[max_quantity]"]').value !== '';
        });
        if (allLimited) {
            var capacity = selected.reduce(function (sum, row) {
                return sum + (parseInt(row.querySelector('input[name$="[max_quantity]"]').value, 10) || 0);
            }, 0);
            if (capacity < total) problems.push('Selected recipes max quantities = ' + capacity + ', less than ' + total + '.');
        }

        if (problems.length) {
            box.className = 'delivery-check small is-error';
            box.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>' + problems.join('<br>');
            return;
        }

        // Example: fewest and most deliveries the customer can choose
        var fewest = Math.max(minD, Math.ceil(total / maxR));
        var most = Math.min(maxD, Math.floor(total / minR));

        box.className = 'delivery-check small is-ok';
        box.innerHTML = '<i class="fas fa-check-circle me-1 text-success"></i>'
            + 'The customer can split <strong>' + total + '</strong> recipes into '
            + '<strong>' + fewest + (most !== fewest ? ' to ' + most : '') + '</strong> deliveries '
            + 'of <strong>' + minR + (maxR !== minR ? '–' + maxR : '') + '</strong> recipes.';
    }

    ['total_recipes', 'min_deliveries', 'max_deliveries', 'min_recipes_per_delivery', 'max_recipes_per_delivery']
        .forEach(function (id) { $(id).addEventListener('input', updateDeliveryCheck); });

    document.querySelectorAll('input[name$="[max_quantity]"]').forEach(function (input) {
        input.addEventListener('input', updateDeliveryCheck);
    });

    /*
    |--------------------------------------------------------------------------
    | PRICE SUMMARY
    |--------------------------------------------------------------------------
    */
    function updatePriceSummary() {
        var price = num('price'), promo = num('promo_price'), total = num('total_recipes');
        var currency = ($('currency').value || 'KWD').toUpperCase();
        var hasPromo = $('promo_price').value !== '';
        var lines = [];

        if (hasPromo && price > 0) {
            if (promo >= price) {
                lines.push('<span class="text-danger">Promo price must be lower than the price.</span>');
            } else {
                lines.push('Discount: <strong>-' + Math.round((1 - promo / price) * 100) + '%</strong>');
            }
        }

        var payable = hasPromo && promo < price ? promo : price;
        if (total > 0 && payable > 0) {
            lines.push('Price per recipe: <strong>' + (payable / total).toFixed(3) + ' ' + currency + '</strong>');
        }

        var trialOffer = num('trial_offer_price');
        if ($('trial_offer_price').value !== '' && price > 0 && !$('is_trial').checked) {
            if (trialOffer >= price) {
                lines.push('<span class="text-danger">Trial offer price must be lower than the price.</span>');
            } else {
                lines.push('During a trial: <strong>' + trialOffer.toFixed(3) + ' ' + currency
                    + '</strong> (-' + Math.round((1 - trialOffer / price) * 100) + '%)');
            }
        }

        $('priceSummary').innerHTML = lines.length ? lines.join('<br>') : '<span class="text-muted">Enter a price.</span>';
        document.querySelector('.promo-dates').style.display = hasPromo ? '' : 'none';
    }

    ['price', 'promo_price', 'trial_offer_price', 'total_recipes', 'currency'].forEach(function (id) {
        $(id).addEventListener('input', updatePriceSummary);
    });

    /*
    |--------------------------------------------------------------------------
    | AGES (Kitten = cat, Puppy = dog) / TRIAL OFFER (not on trial packs)
    |--------------------------------------------------------------------------
    */
    function toggleLifeStages() {
        document.querySelectorAll('.life-stage-option').forEach(function (option) {
            var only = option.getAttribute('data-species');
            var visible = !only || only === speciesSelect.value;
            option.style.display = visible ? '' : 'none';
            if (!visible) option.querySelector('input').checked = false;
        });
    }

    function toggleTrialOffer() {
        var isTrial = $('is_trial').checked;
        $('trialOfferWrap').style.display = isTrial ? 'none' : '';
        if (isTrial) $('trial_offer_price').value = '';
        updatePriceSummary();
    }

    speciesSelect.addEventListener('change', toggleLifeStages);
    $('is_trial').addEventListener('change', toggleTrialOffer);

    /*
    |--------------------------------------------------------------------------
    | IMAGE PREVIEW
    |--------------------------------------------------------------------------
    */
    // Main image: chosen in the library (see MEDIA LIBRARY below)

    /*
    |--------------------------------------------------------------------------
    | GALLERY: order, remove, preview of the new files
    |--------------------------------------------------------------------------
    */
    var mediaList = $('mediaList');
    var maxMedia = {{ \App\Models\ProductMedia::MAX_PER_PRODUCT }};

    function mediaKeptCount() {
        return mediaList.querySelectorAll('.media-item:not(.is-removed)').length;
    }

    function updateMediaState() {
        $('media_order').value = Array.prototype.map.call(mediaList.querySelectorAll('.media-item'), function (item) {
            return item.getAttribute('data-id');
        }).join(',');

        if (typeof renderChosenGallery === 'function') renderChosenGallery();
    }

    mediaList.addEventListener('click', function (event) {
        var button = event.target.closest('.media-up, .media-down');
        if (!button) return;

        var item = button.closest('.media-item');
        if (button.classList.contains('media-up') && item.previousElementSibling) {
            mediaList.insertBefore(item, item.previousElementSibling);
        } else if (button.classList.contains('media-down') && item.nextElementSibling) {
            mediaList.insertBefore(item.nextElementSibling, item);
        }
        updateMediaState();
    });

    mediaList.addEventListener('change', function (event) {
        if (!event.target.classList.contains('media-remove')) return;
        event.target.closest('.media-item').classList.toggle('is-removed', event.target.checked);
        updateMediaState();
    });

    updateMediaState();

    /*
    |--------------------------------------------------------------------------
    | MEDIA LIBRARY (reuse files already uploaded, nothing uploaded again)
    |--------------------------------------------------------------------------
    */
    var library = [];
    try { library = JSON.parse($('mediaLibraryData').textContent || '[]'); } catch (e) { library = []; }

    var libModalEl = $('mediaLibraryModal');
    var libModal = (window.bootstrap && libModalEl) ? new bootstrap.Modal(libModalEl) : null;
    var libMode = 'gallery';
    var libType = '';
    var libTarget = null;
    var libSelected = {};

    function chosenGalleryPaths() {
        return Array.prototype.map.call(
            $('mediaLibraryChosen').querySelectorAll('input[name="media_existing[]"]'),
            function (input) { return input.value; }
        );
    }

    function libItem(path) {
        return (library || []).find(function (item) { return item.path === path; });
    }

    function thumbHtml(item) {
        return item.type === 'video'
            ? '<video src="' + escapeHtml(item.url) + '#t=0.5" preload="metadata" muted playsinline></video><span class="media-play"><i class="fas fa-play"></i></span>'
            : '<img src="' + escapeHtml(item.url) + '" alt="" loading="lazy">';
    }

    function renderLibrary() {
        var q = ($('libSearch').value || '').trim().toLowerCase();
        var grid = $('libGrid');
        var shown = 0;
        grid.innerHTML = '';

        library.forEach(function (item) {
            if (libMode !== 'gallery' && item.type !== 'image') return;
            // Gallery: the files already in this product's gallery are not proposed again
            if (libMode === 'gallery' && item.own) return;
            if (libType && item.type !== libType) return;
            if (q && ((item.products || '') + ' ' + (item.name || '')).toLowerCase().indexOf(q) === -1) return;

            var el = document.createElement('div');
            el.className = 'lib-item' + (libSelected[item.path] ? ' is-selected' : '');
            el.innerHTML = '<div class="lib-thumb">' + thumbHtml(item) + '</div>'
                + '<span class="lib-check"><i class="fas fa-check"></i></span>'
                + '<div class="lib-label" title="' + escapeHtml(item.products || item.name || '') + '">'
                + escapeHtml(item.products || item.name || 'Not used yet') + '</div>';

            el.addEventListener('click', function () {
                if (libMode !== 'gallery') {
                    libSelected = {};
                    libSelected[item.path] = true;
                } else if (libSelected[item.path]) {
                    delete libSelected[item.path];
                } else {
                    libSelected[item.path] = true;
                }
                renderLibrary();
            });

            grid.appendChild(el);
            shown++;
        });

        $('libEmpty').style.display = shown ? 'none' : '';
        var count = Object.keys(libSelected).length;
        $('libSelectedInfo').textContent = count ? count + ' selected' : '';
        $('libConfirm').disabled = libMode !== 'gallery' && count === 0;
    }

    function openLibrary(mode) {
        libMode = mode;
        libSelected = {};
        $('libUploadStatus').innerHTML = '';
        $('libUploadInput').setAttribute('accept', mode !== 'gallery'
            ? 'image/jpeg,image/png,image/webp'
            : 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm,video/x-m4v');

        if (mode === 'gallery') {
            chosenGalleryPaths().forEach(function (path) { libSelected[path] = true; });
        } else if (mode === 'content') {
            var current = $('content_image_' + libTarget);
            if (current && current.value) libSelected[current.value] = true;
        } else if ($('image_existing').value) {
            libSelected[$('image_existing').value] = true;
        }

        $('mediaLibraryTitle').innerHTML = '<i class="fas fa-images me-1"></i>'
            + (mode === 'cover' ? 'Choose the main image' : (mode === 'content' ? 'Image of the item' : 'Add images / videos to the gallery'));
        document.querySelectorAll('#libTypeFilter button').forEach(function (button) {
            button.style.display = mode !== 'gallery' && button.getAttribute('data-type') !== '' ? 'none' : '';
        });

        renderLibrary();
        if (libModal) libModal.show();
    }

    function renderChosenGallery() {
        var box = $('mediaLibraryChosen');
        var paths = chosenGalleryPaths();
        box.querySelectorAll('.media-new-item').forEach(function (el) { el.remove(); });

        paths.forEach(function (path) {
            var item = libItem(path);
            if (!item) return;

            var el = document.createElement('div');
            el.className = 'media-new-item';
            el.innerHTML = '<div class="media-thumb">' + thumbHtml(item) + '</div>'
                + '<span class="badge bg-info text-dark badge-library">library</span>'
                + '<button type="button" class="lib-remove" title="Remove">&times;</button>';
            el.querySelector('.lib-remove').addEventListener('click', function () {
                var input = box.querySelector('input[value="' + CSS.escape(path) + '"]');
                if (input) input.remove();
                renderChosenGallery();
            });
            box.appendChild(el);
        });

        // Counter of the gallery (kept + new files + library)
        var total = mediaKeptCount() + paths.length;
        var badge = $('mediaCount');
        badge.textContent = total + ' / ' + maxMedia;
        badge.className = 'badge ' + (total > maxMedia ? 'bg-danger' : 'bg-mauve');
    }

    $('libConfirm').addEventListener('click', function () {
        var chosen = Object.keys(libSelected);

        if (libMode === 'content') {
            var contentItem = libItem(chosen[0]);
            if (contentItem) {
                $('content_image_' + libTarget).value = contentItem.path;
                $('content_preview_' + libTarget).src = contentItem.url;
                $('content_preview_' + libTarget).classList.remove('d-none');
                $('content_icon_' + libTarget).classList.add('d-none');
            }
        } else if (libMode === 'cover') {
            var item = libItem(chosen[0]);
            if (item) {
                $('image_existing').value = item.path;
                $('imagePreview').src = item.url;
                $('imagePreview').classList.remove('d-none');
                if ($('remove_image')) $('remove_image').checked = false;
            }
        } else {
            var box = $('mediaLibraryChosen');
            box.querySelectorAll('input[name="media_existing[]"]').forEach(function (input) { input.remove(); });
            chosen.forEach(function (path) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'media_existing[]';
                input.value = path;
                box.appendChild(input);
            });
            renderChosenGallery();
        }

        if (libModal) libModal.hide();
    });

    document.querySelectorAll('[data-library-mode]').forEach(function (button) {
        button.addEventListener('click', function () {
            libTarget = button.getAttribute('data-content-index');
            openLibrary(button.getAttribute('data-library-mode'));
        });
    });

    // "What's inside the pack": clear one line
    document.querySelectorAll('.content-clear').forEach(function (button) {
        button.addEventListener('click', function () {
            var row = button.closest('.content-row');
            var index = button.getAttribute('data-content-index');
            row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
            $('content_preview_' + index).classList.add('d-none');
            $('content_preview_' + index).removeAttribute('src');
            $('content_icon_' + index).classList.remove('d-none');
        });
    });

    $('libSearch').addEventListener('input', renderLibrary);
    document.querySelectorAll('#libTypeFilter button').forEach(function (button) {
        button.addEventListener('click', function () {
            libType = button.getAttribute('data-type');
            document.querySelectorAll('#libTypeFilter button').forEach(function (other) {
                other.className = 'btn btn-sm ' + (other === button ? 'btn-mauve' : 'btn-outline-secondary');
            });
            renderLibrary();
        });
    });

    /*
     * Upload ONE file into the library (sent at once, with progress):
     * it appears first in the grid and is selected.
     */
    var uploadUrl = @json(route('products.media.upload'));
    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content
        || (document.querySelector('input[name="_token"]') || {}).value || '';

    $('libUploadInput').addEventListener('change', function () {
        var file = this.files && this.files[0];
        var input = this;
        if (!file) return;

        var isVideo = file.type.indexOf('video/') === 0;
        var serverMaxMb = {{ round(($serverUploadLimitKb ?? 51200) / 1024, 1) }};
        var maxMb = Math.min(isVideo ? 50 : 4, serverMaxMb);
        var status = $('libUploadStatus');

        if (libMode !== 'gallery' && isVideo) {
            status.innerHTML = '<span class="text-danger">Choose an image (not a video).</span>';
            input.value = '';
            return;
        }

        if (file.size > maxMb * 1024 * 1024) {
            status.innerHTML = '<span class="text-danger">' + escapeHtml(file.name) + ' (' + (file.size / 1048576).toFixed(1)
                + ' MB): too big, max ' + maxMb + ' MB'
                + (serverMaxMb < (isVideo ? 50 : 4) ? ' on this server (raise post_max_size / upload_max_filesize in cPanel)' : '') + '.</span>';
            input.value = '';
            return;
        }

        var data = new FormData();
        data.append('file', file);
        data.append('_token', csrfToken);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', uploadUrl);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        $('libUploadProgressWrap').classList.remove('d-none');
        $('libUploadProgress').style.width = '0%';
        status.innerHTML = '<span class="text-muted">Uploading ' + escapeHtml(file.name) + '...</span>';
        input.disabled = true;

        xhr.upload.addEventListener('progress', function (event) {
            if (event.lengthComputable) {
                $('libUploadProgress').style.width = Math.round(event.loaded / event.total * 100) + '%';
            }
        });

        xhr.onload = function () {
            input.disabled = false;
            input.value = '';
            $('libUploadProgressWrap').classList.add('d-none');

            var response = null;
            try { response = JSON.parse(xhr.responseText); } catch (e) { response = null; }

            if (xhr.status >= 200 && xhr.status < 300 && response && response.item) {
                library.unshift(response.item);
                if (libMode !== 'gallery') libSelected = {};
                libSelected[response.item.path] = true;
                status.innerHTML = '<span class="text-success"><i class="fas fa-check me-1"></i>'
                    + escapeHtml(file.name) + ' uploaded and selected. You can upload another one.</span>';
                renderLibrary();
                return;
            }

            var message = (response && (response.message || (response.errors && Object.values(response.errors)[0][0])))
                || (xhr.status === 413
                    ? 'File too big for the server: raise post_max_size and upload_max_filesize in cPanel (PHP).'
                    : 'Upload failed (HTTP ' + xhr.status + ').');
            status.innerHTML = '<span class="text-danger">' + escapeHtml(message) + '</span>';
        };

        xhr.onerror = function () {
            input.disabled = false;
            input.value = '';
            $('libUploadProgressWrap').classList.add('d-none');
            status.innerHTML = '<span class="text-danger">Network error: upload failed.</span>';
        };

        xhr.send(data);
    });

    // Counter also follows the library choices
    mediaList.addEventListener('change', renderChosenGallery);
    mediaList.addEventListener('click', function () { setTimeout(renderChosenGallery, 0); });
    renderChosenGallery();

    /*
    |--------------------------------------------------------------------------
    | SHOPIFY: product / variant picker
    |--------------------------------------------------------------------------
    */
    var shopifySelect = $('shopifyVariantSelect');

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function updateShopifySelection() {
        if (!shopifySelect) return;

        var option = shopifySelect.options[shopifySelect.selectedIndex];
        var box = $('shopifySelected');

        $('shopify_variant_id').value = option ? option.value : '';
        $('shopify_product_id').value = option ? (option.getAttribute('data-product') || '') : '';

        if (!option || !option.value || !option.getAttribute('data-product-title')) {
            box.innerHTML = option && option.value
                ? '<span class="text-muted">Variant #' + escapeHtml(option.value) + '</span>'
                : '';
            return;
        }

        var image = option.getAttribute('data-image');
        var sku = option.getAttribute('data-sku');
        var price = option.getAttribute('data-price');

        box.innerHTML = (image ? '<img src="' + escapeHtml(image) + '" alt="">' : '')
            + '<div><div class="fw-semibold">' + escapeHtml(option.getAttribute('data-product-title')) + '</div>'
            + '<div>' + escapeHtml(option.getAttribute('data-variant-title'))
            + (sku ? ' · <span class="text-muted">' + escapeHtml(sku) + '</span>' : '')
            + (price ? ' · <strong>' + escapeHtml(price) + '</strong>' : '') + '</div>'
            + '<div class="text-muted small">Product #' + escapeHtml(option.getAttribute('data-product'))
            + ' · Variant #' + escapeHtml(option.value) + '</div></div>';
    }

    if (shopifySelect) {
        shopifySelect.addEventListener('change', updateShopifySelection);

        $('shopifySearch').addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();

            Array.prototype.forEach.call(shopifySelect.querySelectorAll('optgroup'), function (group) {
                var groupMatch = group.label.toLowerCase().indexOf(q) !== -1;
                var visibleCount = 0;

                Array.prototype.forEach.call(group.querySelectorAll('option'), function (option) {
                    var visible = !q || groupMatch || (option.getAttribute('data-search') || '').indexOf(q) !== -1;
                    option.hidden = !visible;
                    if (visible) visibleCount++;
                });

                group.hidden = visibleCount === 0;
            });
        });

        updateShopifySelection();
    }

    /*
    |--------------------------------------------------------------------------
    | INIT
    |--------------------------------------------------------------------------
    */
    toggleBreedSize();
    toggleLifeStages();
    filterRecipes();
    toggleTrialOffer();
});
</script>

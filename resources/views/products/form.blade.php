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

                <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp"
                       class="form-control form-control-sm @error('image') is-invalid @enderror">
                @error('image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">JPG, PNG or WebP, max 4 MB.</div>

                @if($product->image)
                    <div class="form-check mt-2">
                        <input type="hidden" name="remove_image" value="0">
                        <input class="form-check-input" type="checkbox" id="remove_image" name="remove_image" value="1">
                        <label class="form-check-label small" for="remove_image">Remove current image</label>
                    </div>
                @endif
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
    <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-mauve">
        <i class="fas fa-save me-1"></i>{{ $product->exists ? 'Save changes' : 'Create product' }}
    </button>
</div>

<style>
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
    $('image').addEventListener('change', function () {
        var file = this.files && this.files[0];
        var preview = $('imagePreview');
        if (!file) return;
        preview.src = URL.createObjectURL(file);
        preview.classList.remove('d-none');
        if ($('remove_image')) $('remove_image').checked = false;
    });

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

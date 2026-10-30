<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Recipe;
use App\Services\ShopifyCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ProductController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | LIST
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): View
    {
        $this->authorizePermission('products.view');

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'species' => $request->query('species', ''),
            'type' => $request->query('type', ''),
            'trial' => $request->query('trial', ''),
            'status' => $request->query('status', ''),
            'age' => $request->query('age', ''),
        ];

        $products = Product::query()
            ->withCount('recipes')
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $search = '%' . $filters['search'] . '%';

                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('name_ar', 'like', $search);
                });
            })
            ->when(in_array($filters['species'], Product::SPECIES, true),
                fn ($query) => $query->where('species', $filters['species']))
            ->when(in_array($filters['type'], Product::TYPES, true),
                fn ($query) => $query->where('product_type', $filters['type']))
            ->when($filters['trial'] === '1', fn ($query) => $query->where('is_trial', true))
            ->when($filters['trial'] === '0', fn ($query) => $query->where('is_trial', false))
            ->when(in_array($filters['age'], Product::LIFE_STAGES, true),
                fn ($query) => $query->forLifeStage($filters['age']))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('species')
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();

        return view('products.index', compact('products', 'filters'));
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    public function create(Request $request): View
    {
        $this->authorizePermission('products.create');

        return view('products.create', [
            'product' => new Product([
                'species' => 'Cat',
                'product_type' => 'regular',
                'currency' => 'KWD',
                'duration_value' => 1,
                'duration_unit' => 'month',
                'min_deliveries' => 1,
                'max_deliveries' => 4,
                'min_recipes_per_delivery' => 1,
                'max_recipes_per_delivery' => 30,
                'is_active' => true,
                'sort_order' => 0,
            ]),
            'recipes' => $this->recipesForForm(),
            'selectedRecipes' => [],
            'shopifyCatalog' => $this->shopifyCatalog($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('products.create');

        [$data, $recipeSync] = $this->validateProduct($request);

        DB::transaction(function () use ($request, $data, $recipeSync) {
            if ($request->hasFile('image')) {
                $data['image'] = $request->file('image')->store('products', 'public');
            }

            $product = Product::create($data);
            $product->recipes()->sync($recipeSync);
        });

        return redirect()
            ->route('products.index')
            ->with('success', 'Product created successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT
    |--------------------------------------------------------------------------
    */

    public function edit(Request $request, Product $product): View
    {
        $this->authorizePermission('products.update');

        $product->load('recipes');

        $selectedRecipes = $product->recipes
            ->mapWithKeys(fn ($recipe) => [
                $recipe->id => [
                    'selected' => true,
                    'max_quantity' => $recipe->pivot->max_quantity,
                    'is_default' => (bool) $recipe->pivot->is_default,
                ],
            ])
            ->all();

        return view('products.edit', [
            'product' => $product,
            'recipes' => $this->recipesForForm(),
            'selectedRecipes' => $selectedRecipes,
            'shopifyCatalog' => $this->shopifyCatalog($request),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->authorizePermission('products.update');

        [$data, $recipeSync] = $this->validateProduct($request, $product);

        DB::transaction(function () use ($request, $product, $data, $recipeSync) {
            $oldImage = $product->image;

            if ($request->boolean('remove_image')) {
                $data['image'] = null;
            }

            if ($request->hasFile('image')) {
                $data['image'] = $request->file('image')->store('products', 'public');
            }

            $product->update($data);
            $product->recipes()->sync($recipeSync);

            // Old file removed only once the new state is saved
            if ($oldImage && $oldImage !== $product->image) {
                Storage::disk('public')->delete($oldImage);
            }
        });

        return redirect()
            ->route('products.index')
            ->with('success', 'Product updated successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVATE / DEACTIVATE
    |--------------------------------------------------------------------------
    */

    public function toggleActive(Product $product): RedirectResponse
    {
        $this->authorizePermission('products.update');

        $product->update(['is_active' => !$product->is_active]);

        return back()->with(
            'success',
            $product->is_active ? 'Product activated.' : 'Product deactivated.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE (soft delete: purchase history is kept)
    |--------------------------------------------------------------------------
    */

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorizePermission('products.delete');

        $product->delete();

        return redirect()
            ->route('products.index')
            ->with('success', 'Product deleted.');
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    |
    | Returns [product columns, recipes to sync].
    |
    */

    private function validateProduct(Request $request, ?Product $product = null): array
    {
        $validator = Validator::make($request->all(), [
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('products', 'code')->ignore($product?->id),
            ],
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],

            'species' => ['required', Rule::in(Product::SPECIES)],
            'product_type' => ['required', Rule::in(Product::TYPES)],
            'is_trial' => ['nullable', 'boolean'],
            'breed_size_category' => ['nullable', Rule::in(Product::BREED_SIZES)],
            'life_stages' => ['nullable', 'array'],
            'life_stages.*' => [Rule::in(Product::LIFE_STAGES)],

            'price' => ['required', 'numeric', 'min:0', 'max:9999999', 'decimal:0,3'],
            'promo_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', 'lt:price'],
            'promo_starts_at' => ['nullable', 'date'],
            'promo_ends_at' => ['nullable', 'date', 'after:promo_starts_at'],
            'trial_offer_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', 'lt:price'],
            'currency' => ['required', 'string', 'size:3'],

            'duration_value' => ['required', 'integer', 'min:1', 'max:1000'],
            'duration_unit' => ['required', Rule::in(Product::DURATION_UNITS)],

            'total_recipes' => ['required', 'integer', 'min:1', 'max:10000'],

            'min_deliveries' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_deliveries' => ['required', 'integer', 'min:1', 'max:1000', 'gte:min_deliveries'],
            'min_recipes_per_delivery' => ['required', 'integer', 'min:1', 'max:10000'],
            'max_recipes_per_delivery' => ['required', 'integer', 'min:1', 'max:10000', 'gte:min_recipes_per_delivery'],
            'min_days_between_deliveries' => ['nullable', 'integer', 'min:0', 'max:365'],

            'max_purchases_per_customer' => ['nullable', 'integer', 'min:1', 'max:1000'],

            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'shopify_product_id' => ['nullable', 'string', 'max:100'],
            'shopify_variant_id' => ['nullable', 'string', 'max:100'],

            'recipes' => ['nullable', 'array'],
            'recipes.*.selected' => ['nullable', 'boolean'],
            'recipes.*.max_quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'recipes.*.is_default' => ['nullable', 'boolean'],
        ], [
            'promo_price.lt' => 'The promotional price must be lower than the price.',
            'promo_ends_at.after' => 'The promotion end date must be after its start date.',
            'trial_offer_price.lt' => 'The trial offer price must be lower than the price.',
            'max_deliveries.gte' => 'Max deliveries must be greater than or equal to min deliveries.',
            'max_recipes_per_delivery.gte' => 'Max recipes per delivery must be greater than or equal to the minimum.',
            'code.regex' => 'The code may only contain letters, numbers, - and _.',
        ]);

        /*
         * Selected recipes, in the order of the form.
         */
        $selected = collect($request->input('recipes', []))
            ->filter(fn ($row) => !empty($row['selected']))
            ->map(fn ($row, $recipeId) => [
                'recipe_id' => (int) $recipeId,
                'max_quantity' => filled($row['max_quantity'] ?? null) ? (int) $row['max_quantity'] : null,
                'is_default' => !empty($row['is_default']),
            ])
            ->values();

        $validator->after(function ($validator) use ($request, $selected) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $total = (int) $request->total_recipes;
            $minDeliveries = (int) $request->min_deliveries;
            $maxDeliveries = (int) $request->max_deliveries;
            $minPerDelivery = (int) $request->min_recipes_per_delivery;
            $maxPerDelivery = (int) $request->max_recipes_per_delivery;

            /*
             * Delivery rules must allow at least one valid distribution.
             */
            if ($maxDeliveries * $maxPerDelivery < $total) {
                $validator->errors()->add(
                    'max_recipes_per_delivery',
                    "Impossible to deliver {$total} recipes: max deliveries × max recipes per delivery = "
                    . ($maxDeliveries * $maxPerDelivery) . '.'
                );
            }

            if ($minDeliveries * $minPerDelivery > $total) {
                $validator->errors()->add(
                    'min_recipes_per_delivery',
                    "Min deliveries × min recipes per delivery = "
                    . ($minDeliveries * $minPerDelivery) . " exceeds the {$total} recipes of the pack."
                );
            }

            if ($maxPerDelivery > $total) {
                $validator->errors()->add(
                    'max_recipes_per_delivery',
                    'Max recipes per delivery cannot exceed the total recipes of the pack.'
                );
            }

            /*
             * Deliveries must fit in the duration when a minimum gap is set.
             */
            if (filled($request->min_days_between_deliveries) && (int) $request->min_days_between_deliveries > 0) {
                $durationDays = (new Product([
                    'duration_value' => (int) $request->duration_value,
                    'duration_unit' => $request->duration_unit,
                ]))->durationInDays();

                $neededDays = ($minDeliveries - 1) * (int) $request->min_days_between_deliveries + 1;

                if ($neededDays > $durationDays) {
                    $validator->errors()->add(
                        'min_days_between_deliveries',
                        "{$minDeliveries} deliveries with {$request->min_days_between_deliveries} days between them "
                        . "need {$neededDays} days, but the pack lasts {$durationDays} days."
                    );
                }
            }

            /*
             * Breed size only for dogs.
             */
            if ($request->species !== 'Dog' && filled($request->breed_size_category)) {
                $validator->errors()->add('breed_size_category', 'Dog size can only be set for dog packs.');
            }

            /*
             * Ages must exist for the species (Kitten = cat, Puppy = dog).
             */
            $allowedStages = Product::LIFE_STAGES_BY_SPECIES[$request->species] ?? Product::LIFE_STAGES;

            foreach ((array) $request->input('life_stages', []) as $stage) {
                if (!in_array($stage, $allowedStages, true)) {
                    $validator->errors()->add(
                        'life_stages',
                        "\"{$stage}\" is not an age for {$request->species} packs."
                    );
                }
            }

            /*
             * Trial offer price: only on normal packs, and cheaper than the promo price.
             */
            if (filled($request->trial_offer_price)) {
                if ($request->boolean('is_trial')) {
                    $validator->errors()->add(
                        'trial_offer_price',
                        'A trial pack cannot have a trial offer price. Set it on the pack you want to offer after the trial.'
                    );
                }
            }

            /*
             * Recipes: at least one, existing, same species.
             */
            if ($selected->isEmpty()) {
                $validator->errors()->add('recipes', 'Select at least one recipe for this pack.');
                return;
            }

            $recipes = Recipe::whereIn('id', $selected->pluck('recipe_id'))->get()->keyBy('id');

            foreach ($selected as $row) {
                $recipe = $recipes->get($row['recipe_id']);

                if (!$recipe) {
                    $validator->errors()->add('recipes', "Recipe #{$row['recipe_id']} does not exist.");
                    continue;
                }

                $recipeSpecies = $recipe->species ?? null;

                if ($recipeSpecies && $recipeSpecies !== $request->species) {
                    $validator->errors()->add(
                        'recipes',
                        "\"{$recipe->name}\" is a {$recipeSpecies} recipe and cannot be added to a {$request->species} pack."
                    );
                }
            }

            /*
             * When every recipe has a max quantity, their sum must cover the pack.
             */
            if ($selected->every(fn ($row) => $row['max_quantity'] !== null)) {
                $capacity = $selected->sum('max_quantity');

                if ($capacity < $total) {
                    $validator->errors()->add(
                        'recipes',
                        "The max quantities of the selected recipes add up to {$capacity}, less than the {$total} recipes of the pack."
                    );
                }
            }
        });

        $validated = $validator->validate();

        $data = collect($validated)
            ->except(['image', 'remove_image', 'recipes'])
            ->all();

        $data['is_trial'] = $request->boolean('is_trial');
        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $data['currency'] = strtoupper($validated['currency']);
        $data['code'] = strtoupper($validated['code']);

        if ($data['species'] !== 'Dog') {
            $data['breed_size_category'] = null;
        }

        // No age ticked = all ages (NULL). Ages kept in the species order.
        $data['life_stages'] = array_values(array_intersect(
            Product::LIFE_STAGES_BY_SPECIES[$data['species']] ?? Product::LIFE_STAGES,
            (array) ($validated['life_stages'] ?? [])
        ));

        if (!filled($data['trial_offer_price'] ?? null) || $data['is_trial']) {
            $data['trial_offer_price'] = null;
        }

        // Empty promo = no promo dates either
        if (!filled($data['promo_price'] ?? null)) {
            $data['promo_price'] = null;
            $data['promo_starts_at'] = null;
            $data['promo_ends_at'] = null;
        }

        $recipeSync = $selected
            ->values()
            ->mapWithKeys(fn ($row, $index) => [
                $row['recipe_id'] => [
                    'max_quantity' => $row['max_quantity'],
                    'is_default' => $row['is_default'],
                    'sort_order' => $index,
                ],
            ])
            ->all();

        return [$data, $recipeSync];
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Shopify products + variants for the form select.
     * ?refresh_shopify=1 reloads them from the store.
     * Never blocks the form: on error the IDs can still be typed.
     */
    private function shopifyCatalog(Request $request): array
    {
        try {
            $service = app(ShopifyCatalogService::class);

            $products = $request->boolean('refresh_shopify')
                ? $service->refresh()
                : $service->products();

            return ['products' => $products, 'error' => null];
        } catch (Throwable $exception) {
            report($exception);

            return ['products' => [], 'error' => $exception->getMessage()];
        }
    }

    private function recipesForForm()
    {
        return Recipe::query()
            ->orderBy('name')
            ->get()
            ->sortBy(fn ($recipe) => ($recipe->species ?? 'Cat') . '|' . $recipe->name)
            ->values();
    }

    private function authorizePermission(string $permission): void
    {
        $user = auth()->user();

        abort_unless($user && $user->hasPermission($permission), 403);
    }
}

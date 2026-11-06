<?php

namespace App\Http\Controllers;

use App\Models\MediaLibraryItem;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Recipe;
use App\Services\ShopifyCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
            ->withCount(['recipes', 'media'])
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
            'mediaLibrary' => $this->mediaLibrary(),
            'serverUploadLimitKb' => self::serverUploadLimitKb(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('products.create');

        [$data, $recipeSync] = $this->validateProduct($request);

        DB::transaction(function () use ($request, $data, $recipeSync) {
            if ($request->hasFile('image')) {
                $data['image'] = $request->file('image')->store('products', 'public');
            } elseif ($libraryImage = $this->libraryImage($request)) {
                $data['image'] = $libraryImage;
            }

            $product = Product::create($data);
            $product->recipes()->sync($recipeSync);

            // Gallery: images + videos
            $this->saveMedia($request, $product);
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

        $product->load(['recipes', 'media']);

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
            'mediaLibrary' => $this->mediaLibrary($product),
            'serverUploadLimitKb' => self::serverUploadLimitKb(),
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
            } elseif ($libraryImage = $this->libraryImage($request)) {
                $data['image'] = $libraryImage;
            }

            $product->update($data);
            $product->recipes()->sync($recipeSync);

            // Gallery: removed / order / new images and videos
            $this->saveMedia($request, $product);

            // Old file removed only once the new state is saved
            if ($oldImage && $oldImage !== $product->image) {
                $this->deleteFileIfUnused($oldImage);
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
    | DUPLICATE
    |--------------------------------------------------------------------------
    |
    | Copy of the SAVED product: same rules, prices, recipes, images and
    | videos (same files, nothing copied on the server). The copy is INACTIVE (checked before showing it in the app),
    | its code ends with -COPY, it is not linked to Shopify.
    |
    */

    public function duplicate(Product $product): RedirectResponse
    {
        $this->authorizePermission('products.create');

        $product->load(['recipes', 'media']);

        $copy = DB::transaction(function () use ($product) {
            $copy = $product->replicate(['image', 'shopify_product_id', 'shopify_variant_id']);

            $copy->code = $this->copyCode($product->code);
            $copy->name = Str::limit($product->name . ' (copy)', 191, '');
            $copy->name_ar = filled($product->name_ar) ? Str::limit($product->name_ar . ' (نسخة)', 191, '') : null;
            $copy->is_active = false;
            $copy->shopify_product_id = null;
            $copy->shopify_variant_id = null;
            // Same files as the original (no copy): a file is deleted only
            // when no product uses it any more
            $copy->image = $product->image;
            $copy->save();

            // Recipes with their limits
            $copy->recipes()->sync(
                $product->recipes->mapWithKeys(fn ($recipe) => [
                    $recipe->id => [
                        'max_quantity' => $recipe->pivot->max_quantity,
                        'is_default' => (bool) $recipe->pivot->is_default,
                        'sort_order' => (int) $recipe->pivot->sort_order,
                    ],
                ])->all()
            );

            // Images and videos: the same files
            foreach ($product->media as $item) {
                $copy->media()->create([
                    'type' => $item->type,
                    'path' => $item->path,
                    'mime' => $item->mime,
                    'size' => $item->size,
                    'sort_order' => $item->sort_order,
                ]);
            }

            return $copy;
        });

        return redirect()
            ->route('products.edit', $copy)
            ->with('success', "Product duplicated: \"{$copy->code}\" (inactive). Check it, then activate it.");
    }

    /** CAT-REG-1M -> CAT-REG-1M-COPY, then -COPY2, -COPY3... (max 50 characters) */
    private function copyCode(string $code): string
    {
        $base = Str::limit(strtoupper($code), 40, '') . '-COPY';
        $candidate = $base;
        $number = 2;

        while (Product::withTrashed()->where('code', $candidate)->exists()) {
            $candidate = $base . $number++;
        }

        return $candidate;
    }

    /*
    |--------------------------------------------------------------------------
    | LIBRARY UPLOAD (popup of the product form, one file at a time)
    |--------------------------------------------------------------------------
    |
    | POST /products-media/upload   { file }  -> JSON item of the library
    | The file is then chosen like any other file of the library.
    |
    */

    public function uploadMedia(Request $request): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user && ($user->hasPermission('products.create') || $user->hasPermission('products.update')), 403);

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'mimes:' . implode(',', array_merge(ProductMedia::IMAGE_EXTENSIONS, ProductMedia::VIDEO_EXTENSIONS)),
                'max:' . ProductMedia::MAX_VIDEO_KB,
            ],
        ], [
            'file.mimes' => 'Images JPG, PNG, WebP or videos MP4, MOV, WebM only.',
            'file.max' => 'File too big (videos max 50 MB).',
            'file.uploaded' => 'The file could not be uploaded (too big for the server?).',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('file');
        $isVideo = $this->isVideo($file);

        if (!$isVideo && $file->getSize() > ProductMedia::MAX_IMAGE_KB * 1024) {
            return response()->json(['success' => false, 'message' => 'Image too big (max 4 MB).'], 422);
        }

        $item = MediaLibraryItem::create([
            'type' => $isVideo ? ProductMedia::TYPE_VIDEO : ProductMedia::TYPE_IMAGE,
            'path' => $file->store('products/media', 'public'),
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'item' => [
                'path' => $item->path,
                'url' => $item->url,
                'type' => $item->type,
                'mime' => $item->mime,
                'size' => $item->size,
                'name' => $item->original_name,
                'products' => '',
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GALLERY (images + videos)
    |--------------------------------------------------------------------------
    |
    | media_files[]   new files (images / videos), added at the end
    | media_remove[]  ids to remove
    | media_order     "12,5,9": order of the kept items
    |
    */

    private function saveMedia(Request $request, Product $product): void
    {
        $removeIds = collect($request->input('media_remove', []))->map(fn ($id) => (int) $id)->filter()->all();

        if ($removeIds) {
            $product->media()->whereIn('id', $removeIds)->get()->each(function (ProductMedia $item) {
                $item->delete();
                // The same file may be used by other products (library)
                $this->deleteFileIfUnused($item->path);
            });
        }

        // Order chosen in the form
        $order = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('media_order', '')))));

        foreach ($order as $position => $id) {
            $product->media()->whereKey($id)->update(['sort_order' => $position]);
        }

        $next = (int) $product->media()->max('sort_order') + 1;

        /*
         * Chosen in the library: the SAME file is used (nothing uploaded
         * again, nothing copied). Only files of the library are accepted.
         */
        $library = collect($this->mediaLibrary())->keyBy('path');
        $alreadyHere = $product->media()->pluck('path')->all();

        foreach (array_unique((array) $request->input('media_existing', [])) as $path) {
            $entry = $library->get((string) $path);

            if (!$entry || in_array($entry['path'], $alreadyHere, true)) {
                continue;
            }

            $product->media()->create([
                'type' => $entry['type'],
                'path' => $entry['path'],
                'mime' => $entry['mime'],
                'size' => $entry['size'],
                'sort_order' => $next++,
            ]);

            $alreadyHere[] = $entry['path'];
        }

        foreach ((array) $request->file('media_files', []) as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }

            $isVideo = $this->isVideo($file);

            $product->media()->create([
                'type' => $isVideo ? ProductMedia::TYPE_VIDEO : ProductMedia::TYPE_IMAGE,
                'path' => $file->store('products/media', 'public'),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'sort_order' => $next++,
            ]);
        }
    }

    /**
     * Every image / video already on the server: main images of the
     * products + their galleries (one entry per file).
     *
     * @return array<int, array{path: string, url: string, type: string, mime: ?string, size: ?int, products: string}>
     */
    private function mediaLibrary(?Product $except = null): array
    {
        $files = [];

        $add = function (string $path, string $type, ?string $mime, ?int $size, ?string $productLabel, ?string $name = null) use (&$files) {
            if (!isset($files[$path])) {
                $files[$path] = [
                    'path' => $path,
                    'url' => asset('storage/app/public/' . $path),
                    'type' => $type,
                    'mime' => $mime,
                    'size' => $size,
                    'name' => $name,
                    'products' => [],
                ];
            }

            if ($productLabel) {
                $files[$path]['products'][$productLabel] = true;
            }
        };

        // Files uploaded in the library (newest first), used or not
        try {
            MediaLibraryItem::query()
                ->orderByDesc('id')
                ->get()
                ->each(fn (MediaLibraryItem $item) => $add($item->path, $item->type, $item->mime, $item->size, null, $item->original_name));
        } catch (Throwable) {
            // table not created yet
        }

        Product::withTrashed()
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->get(['id', 'code', 'image'])
            ->each(fn (Product $item) => $add($item->image, ProductMedia::TYPE_IMAGE, null, null, $item->code . ' (main)'));

        // Galleries of ALL the products (images + videos)
        $codes = Product::withTrashed()->pluck('code', 'id');

        ProductMedia::query()
            ->orderByDesc('id')
            ->get()
            ->each(fn (ProductMedia $item) => $add(
                $item->path,
                $item->type,
                $item->mime,
                $item->size,
                ($codes[$item->product_id] ?? '#' . $item->product_id) . ' (gallery)'
            ));

        // Already in THIS product's gallery: flagged (hidden only when adding to the gallery)
        $own = $except ? $except->media()->pluck('path')->all() : [];

        return collect($files)
            ->map(fn ($file) => array_merge($file, [
                'products' => implode(', ', array_keys($file['products'])),
                'own' => in_array($file['path'], $own, true),
            ]))
            ->values()
            ->all();
    }

    /**
     * Biggest file PHP accepts on this server (KB): the smallest of
     * upload_max_filesize and post_max_size (cPanel > Select PHP Version).
     */
    public static function serverUploadLimitKb(): int
    {
        $toKb = function ($value): int {
            $value = trim((string) $value);

            if ($value === '' || $value === '0' || $value === '-1') {
                return PHP_INT_MAX;
            }

            $number = (float) $value;

            return (int) match (strtolower(substr($value, -1))) {
                'g' => $number * 1024 * 1024,
                'm' => $number * 1024,
                'k' => $number,
                default => $number / 1024,
            };
        };

        return min($toKb(ini_get('upload_max_filesize')), $toKb(ini_get('post_max_size')));
    }

    /** Main image chosen in the library (images of the library only). */
    private function libraryImage(Request $request): ?string
    {
        $path = (string) $request->input('image_existing', '');

        if ($path === '') {
            return null;
        }

        $entry = collect($this->mediaLibrary())->firstWhere('path', $path);

        return $entry && $entry['type'] === ProductMedia::TYPE_IMAGE ? $entry['path'] : null;
    }

    /** A file is deleted only when no product uses it any more. */
    private function deleteFileIfUnused(?string $path): void
    {
        if (!$path) {
            return;
        }

        $used = ProductMedia::where('path', $path)->exists()
            || Product::withTrashed()->where('image', $path)->exists();

        // Files of the library stay (usable later by other products)
        try {
            $used = $used || MediaLibraryItem::where('path', $path)->exists();
        } catch (Throwable) {
            // table not created yet
        }

        if (!$used) {
            Storage::disk('public')->delete($path);
        }
    }

    private function isVideo($file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        return in_array($extension, ProductMedia::VIDEO_EXTENSIONS, true)
            || str_starts_with((string) $file->getMimeType(), 'video/');
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
            'subtitle' => ['nullable', 'string', 'max:191'],
            'subtitle_ar' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],
            'image_existing' => ['nullable', 'string', 'max:255'],

            // Gallery (images + videos)
            'media_files' => ['nullable', 'array'],
            'media_files.*' => [
                'file',
                'mimes:' . implode(',', array_merge(ProductMedia::IMAGE_EXTENSIONS, ProductMedia::VIDEO_EXTENSIONS)),
                'max:' . ProductMedia::MAX_VIDEO_KB,
            ],
            'media_existing' => ['nullable', 'array', 'max:' . ProductMedia::MAX_PER_PRODUCT],
            'media_existing.*' => ['string', 'max:255'],
            'media_remove' => ['nullable', 'array'],
            'media_remove.*' => ['integer'],
            'media_order' => ['nullable', 'string', 'max:2000'],

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

            // Product page of the app
            'bags_count' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'bag_weight_grams' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'free_delivery' => ['nullable', 'boolean'],
            'features' => ['nullable', 'array'],
            'features.*' => [Rule::in(array_keys(Product::FEATURES))],
            'contents' => ['nullable', 'array', 'max:' . Product::MAX_CONTENTS],
            'contents.*.label' => ['nullable', 'string', 'max:100'],
            'contents.*.label_ar' => ['nullable', 'string', 'max:100'],
            'contents.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'contents.*.image' => ['nullable', 'string', 'max:255'],

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
            'media_files.*.mimes' => 'Gallery: images JPG, PNG, WebP or videos MP4, MOV, WebM only.',
            'media_files.*.max' => 'Gallery: a file is too big (videos max 50 MB).',
            'media_files.*.uploaded' => 'Gallery: a file could not be uploaded (too big for the server?).',
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

        $validator->after(function ($validator) use ($request, $selected, $product) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /*
             * Gallery: images max 4 MB, 10 items per product.
             */
            $newFiles = array_filter((array) $request->file('media_files', []));

            foreach ($newFiles as $file) {
                if (!$this->isVideo($file) && $file->getSize() > ProductMedia::MAX_IMAGE_KB * 1024) {
                    $validator->errors()->add('media_files', "Gallery: the image \"{$file->getClientOriginalName()}\" is bigger than 4 MB.");
                }
            }

            $kept = $product
                ? $product->media()->whereNotIn('id', array_map('intval', (array) $request->input('media_remove', [])) ?: [0])->count()
                : 0;

            $fromLibrary = count(array_unique((array) $request->input('media_existing', [])));

            if ($kept + count($newFiles) + $fromLibrary > ProductMedia::MAX_PER_PRODUCT) {
                $validator->errors()->add(
                    'media_files',
                    'Gallery: maximum ' . ProductMedia::MAX_PER_PRODUCT . ' images / videos per product ('
                    . ($kept + count($newFiles) + $fromLibrary) . ' selected).'
                );
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
            ->except(['image', 'image_existing', 'remove_image', 'recipes', 'media_files', 'media_existing', 'media_remove', 'media_order'])
            ->all();

        $data['is_trial'] = $request->boolean('is_trial');
        $data['free_delivery'] = $request->boolean('free_delivery');
        $data['features'] = (array) ($validated['features'] ?? []);

        // "What's inside": filled lines only, images of the library only
        $libraryPaths = collect($this->mediaLibrary())->where('type', ProductMedia::TYPE_IMAGE)->pluck('path')->all();

        $data['contents'] = collect($validated['contents'] ?? [])
            ->filter(fn ($row) => filled($row['label'] ?? null) || filled($row['label_ar'] ?? null))
            ->map(fn ($row) => [
                'label' => trim((string) ($row['label'] ?? '')),
                'label_ar' => trim((string) ($row['label_ar'] ?? '')),
                'quantity' => filled($row['quantity'] ?? null) ? (int) $row['quantity'] : null,
                'image' => in_array($row['image'] ?? null, $libraryPaths, true) ? $row['image'] : null,
            ])
            ->values()
            ->all() ?: null;
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

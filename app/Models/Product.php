<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Product = pack of recipes for one animal.
 *
 * The customer buys the pack and spreads its recipes (total_recipes)
 * over several deliveries, within the limits defined here.
 */
class Product extends Model
{
    use SoftDeletes;

    public const SPECIES = ['Cat', 'Dog'];

    public const TYPES = ['regular', 'premium'];

    public const BREED_SIZES = ['Small', 'Medium', 'Large'];

    public const DURATION_UNITS = ['day', 'week', 'month', 'year'];

    /**
     * Ages (same values as animals.age).
     * Kitten = cats only, Puppy = dogs only.
     */
    public const LIFE_STAGES = ['Kitten', 'Puppy', 'Adult', 'Senior'];

    public const LIFE_STAGES_BY_SPECIES = [
        'Cat' => ['Kitten', 'Adult', 'Senior'],
        'Dog' => ['Puppy', 'Adult', 'Senior'],
    ];

    /**
     * Qualities shown on the product page of the app (icons in the app).
     * Empty = DEFAULT_FEATURES.
     */
    public const FEATURES = [
        'fresh' => ['en' => 'Fresh', 'ar' => 'طازج'],
        'natural' => ['en' => 'Natural', 'ar' => 'طبيعي'],
        'chilled' => ['en' => 'Keep chilled or frozen', 'ar' => 'يحفظ مبرد أو مجمد'],
        'human_grade' => ['en' => 'Human grade', 'ar' => 'صالح للاستهلاك البشري'],
        'no_preservatives' => ['en' => 'No preservatives', 'ar' => 'بدون مواد حافظة'],
        'grain_free' => ['en' => 'Grain free', 'ar' => 'خالي من الحبوب'],
        'vet_approved' => ['en' => 'Vet approved', 'ar' => 'معتمد بيطرياً'],
    ];

    public const DEFAULT_FEATURES = ['fresh', 'natural', 'chilled'];

    /** "What's inside the pack": max lines */
    public const MAX_CONTENTS = 4;

    public const LIFE_STAGE_LABELS = [
        'Kitten' => ['en' => 'Kitten', 'ar' => 'صغير (قطيط)'],
        'Puppy' => ['en' => 'Puppy', 'ar' => 'صغير (جرو)'],
        'Adult' => ['en' => 'Adult', 'ar' => 'بالغ'],
        'Senior' => ['en' => 'Senior', 'ar' => 'كبير السن'],
    ];

    protected $fillable = [
        'code',
        'name',
        'name_ar',
        'subtitle',
        'subtitle_ar',
        'description',
        'description_ar',
        'image',

        'species',
        'product_type',
        'is_trial',
        'breed_size_category',
        'life_stages',

        'price',
        'promo_price',
        'promo_starts_at',
        'promo_ends_at',
        'trial_offer_price',
        'currency',

        'duration_value',
        'duration_unit',

        'total_recipes',

        // Product page of the app
        'bags_count',
        'bag_weight_grams',
        'free_delivery',
        'features',
        'contents',

        'min_deliveries',
        'max_deliveries',
        'min_recipes_per_delivery',
        'max_recipes_per_delivery',
        'min_days_between_deliveries',

        'max_purchases_per_customer',

        'is_active',
        'sort_order',

        'shopify_product_id',
        'shopify_variant_id',
    ];

    protected $casts = [
        'is_trial' => 'boolean',
        'is_active' => 'boolean',
        'free_delivery' => 'boolean',
        'bags_count' => 'integer',
        'bag_weight_grams' => 'integer',
        'contents' => 'array',

        'price' => 'decimal:3',
        'promo_price' => 'decimal:3',
        'promo_starts_at' => 'datetime',
        'promo_ends_at' => 'datetime',
        'trial_offer_price' => 'decimal:3',

        'duration_value' => 'integer',
        'total_recipes' => 'integer',
        'min_deliveries' => 'integer',
        'max_deliveries' => 'integer',
        'min_recipes_per_delivery' => 'integer',
        'max_recipes_per_delivery' => 'integer',
        'min_days_between_deliveries' => 'integer',
        'max_purchases_per_customer' => 'integer',
        'sort_order' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    /**
     * Recipes allowed in the pack.
     * Pivot: max_quantity (null = free), is_default, sort_order.
     */
    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'product_recipes')
            ->withPivot(['max_quantity', 'is_default', 'sort_order'])
            ->withTimestamps()
            ->orderBy('product_recipes.sort_order');
    }

    /**
     * Gallery of the app: extra images and videos, in their order
     * (the "image" column stays the main image).
     */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Purchases of this pack.
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForSpecies(Builder $query, string $species): Builder
    {
        return $query->where('species', $species);
    }

    /**
     * Packs for a dog of a given size: packs for that size + packs for all sizes.
     */
    public function scopeForBreedSize(Builder $query, ?string $breedSize): Builder
    {
        return $query->where(function (Builder $q) use ($breedSize) {
            $q->whereNull('breed_size_category');

            if ($breedSize) {
                $q->orWhere('breed_size_category', $breedSize);
            }
        });
    }

    /**
     * Packs for a pet of a given age: packs for that age + packs for all ages.
     * Unknown / free-text age: every pack.
     */
    public function scopeForLifeStage(Builder $query, ?string $age): Builder
    {
        if (!in_array($age, self::LIFE_STAGES, true)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($age) {
            $q->whereNull('life_stages')
                ->orWhere('life_stages', '')
                ->orWhereRaw('FIND_IN_SET(?, life_stages)', [$age]);
        });
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('price');
    }

    /*
    |--------------------------------------------------------------------------
    | AGES (life stages)
    |--------------------------------------------------------------------------
    |
    | Stored in a SET column: "Adult,Senior". Empty = all ages.
    |
    */

    /**
     * Accepts an array (form) or a string, saves "Adult,Senior" or NULL.
     */
    public function setLifeStagesAttribute($value): void
    {
        $values = is_array($value)
            ? $value
            : array_filter(explode(',', (string) $value));

        $values = array_values(array_intersect(self::LIFE_STAGES, array_map('trim', $values)));

        $this->attributes['life_stages'] = $values ? implode(',', $values) : null;
    }

    /**
     * ['Adult', 'Senior'] ([] = all ages).
     */
    public function lifeStagesList(): array
    {
        $raw = (string) ($this->attributes['life_stages'] ?? '');

        return array_values(array_intersect(self::LIFE_STAGES, array_filter(explode(',', $raw))));
    }

    /**
     * true when the pack suits a pet of this age.
     * Unknown or free-text age (old pets): always true.
     */
    public function fitsLifeStage(?string $age): bool
    {
        $stages = $this->lifeStagesList();

        if (!$stages || !in_array($age, self::LIFE_STAGES, true)) {
            return true;
        }

        return in_array($age, $stages, true);
    }

    public static function lifeStageLabel(?string $stage, string $lang = 'en'): ?string
    {
        if (!$stage) {
            return null;
        }

        return self::LIFE_STAGE_LABELS[$stage][$lang === 'ar' ? 'ar' : 'en'] ?? $stage;
    }

    /**
     * "Adult, Senior" / "All ages".
     */
    public function lifeStagesLabel(string $lang = 'en'): string
    {
        $stages = $this->lifeStagesList();

        if (!$stages) {
            return $lang === 'ar' ? 'جميع الأعمار' : 'All ages';
        }

        return implode($lang === 'ar' ? '، ' : ', ', array_map(
            fn ($stage) => self::lifeStageLabel($stage, $lang),
            $stages
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | PRICE
    |--------------------------------------------------------------------------
    */

    /**
     * true when this pack has a special price for pets in a running trial.
     * (Price applied by App\Support\ProductPricing.)
     */
    public function hasTrialOffer(): bool
    {
        return !$this->is_trial
            && $this->trial_offer_price !== null
            && (float) $this->trial_offer_price < (float) $this->price;
    }

    /**
     * true when a promo price exists and today is inside its dates
     * (a missing date means "no limit" on that side).
     */
    public function hasActivePromo(?CarbonInterface $at = null): bool
    {
        if ($this->promo_price === null) {
            return false;
        }

        $at ??= now();

        if ($this->promo_starts_at && $at->lt($this->promo_starts_at)) {
            return false;
        }

        if ($this->promo_ends_at && $at->gt($this->promo_ends_at)) {
            return false;
        }

        return (float) $this->promo_price < (float) $this->price;
    }

    /**
     * Price to pay now (promo included).
     */
    public function currentPrice(?CarbonInterface $at = null): float
    {
        return $this->hasActivePromo($at)
            ? (float) $this->promo_price
            : (float) $this->price;
    }

    /**
     * Discount in % (0 when no active promo).
     */
    public function discountPercent(?CarbonInterface $at = null): int
    {
        if (!$this->hasActivePromo($at) || (float) $this->price <= 0) {
            return 0;
        }

        return (int) round((1 - (float) $this->promo_price / (float) $this->price) * 100);
    }

    /**
     * Price per recipe (useful to compare packs).
     */
    public function pricePerRecipe(?CarbonInterface $at = null): float
    {
        return $this->total_recipes > 0
            ? round($this->currentPrice($at) / $this->total_recipes, 3)
            : 0.0;
    }

    /*
    |--------------------------------------------------------------------------
    | DURATION
    |--------------------------------------------------------------------------
    */

    /**
     * Last day covered by the pack when it starts on $start.
     * Example: 1 month from 2026-10-12 -> 2026-11-11 (inclusive).
     */
    public function endDateFrom(CarbonInterface|string $start): Carbon
    {
        $start = Carbon::parse($start)->startOfDay();
        $value = max(1, (int) $this->duration_value);

        $end = match ($this->duration_unit) {
            'day' => $start->copy()->addDays($value),
            'week' => $start->copy()->addWeeks($value),
            'year' => $start->copy()->addYearsNoOverflow($value),
            default => $start->copy()->addMonthsNoOverflow($value),
        };

        return $end->subDay();
    }

    /**
     * Approximate length in days (sorting, deliveries spacing).
     */
    public function durationInDays(): int
    {
        $start = Carbon::today();

        return (int) $start->diffInDays($this->endDateFrom($start)) + 1;
    }

    /**
     * "3 months" / "3 أشهر".
     */
    public function durationLabel(string $lang = 'en'): string
    {
        $value = (int) $this->duration_value;
        $unit = $this->duration_unit;

        if ($lang === 'ar') {
            $units = [
                'day' => ['يوم', 'أيام'],
                'week' => ['أسبوع', 'أسابيع'],
                'month' => ['شهر', 'أشهر'],
                'year' => ['سنة', 'سنوات'],
            ];

            [$one, $many] = $units[$unit] ?? ['', ''];

            return $value === 1 ? $one : $value . ' ' . $many;
        }

        return $value . ' ' . $unit . ($value > 1 ? 's' : '');
    }

    /*
    |--------------------------------------------------------------------------
    | DISPLAY
    |--------------------------------------------------------------------------
    */

    public function localizedName(string $lang = 'en'): string
    {
        return $lang === 'ar' && filled($this->name_ar) ? $this->name_ar : $this->name;
    }

    public function localizedSubtitle(string $lang = 'en'): ?string
    {
        return $lang === 'ar' && filled($this->subtitle_ar) ? $this->subtitle_ar : ($this->subtitle ?: null);
    }

    /*
    |--------------------------------------------------------------------------
    | PRODUCT PAGE OF THE APP
    |--------------------------------------------------------------------------
    */

    /** ['fresh', 'natural'] (saved "fresh,natural"; empty = default list). */
    public function featuresList(): array
    {
        $saved = array_values(array_intersect(
            array_keys(self::FEATURES),
            array_filter(explode(',', (string) ($this->attributes['features'] ?? '')))
        ));

        return $saved ?: self::DEFAULT_FEATURES;
    }

    public function setFeaturesAttribute($value): void
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $values = array_values(array_intersect(array_keys(self::FEATURES), array_map('trim', $values)));

        $this->attributes['features'] = $values ? implode(',', $values) : null;
    }

    /** Bags of the pack (badge "5 bags"): bags_count, else the recipes. */
    public function bagsCount(): int
    {
        return (int) ($this->bags_count ?: $this->total_recipes);
    }

    /**
     * "What's inside the pack" for the app.
     *
     * @return array<int, array{label: string, quantity: ?int, image_url: ?string}>
     */
    public function contentsForApi(string $lang = 'en'): array
    {
        return collect($this->contents ?? [])
            ->filter(fn ($row) => is_array($row) && (filled($row['label'] ?? null) || filled($row['label_ar'] ?? null)))
            ->map(fn ($row) => [
                'label' => $lang === 'ar' && filled($row['label_ar'] ?? null) ? $row['label_ar'] : (string) ($row['label'] ?? $row['label_ar']),
                'quantity' => filled($row['quantity'] ?? null) ? (int) $row['quantity'] : null,
                'image_url' => filled($row['image'] ?? null) ? asset('storage/app/public/' . $row['image']) : null,
            ])
            ->values()
            ->all();
    }

    public function localizedDescription(string $lang = 'en'): ?string
    {
        return $lang === 'ar' && filled($this->description_ar)
            ? $this->description_ar
            : $this->description;
    }

    /**
     * Public URL of the image (same storage path as the other app images).
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('storage/app/public/' . $this->image) : null;
    }

    /**
     * Carousel of the app: main image first, then the gallery
     * (images and videos in their order).
     *
     * @return array<int, array{id: string, type: string, url: string, poster: ?string}>
     */
    public function galleryForApi(): array
    {
        $items = [];

        if ($this->image_url) {
            $items[] = ['id' => 'cover', 'type' => 'image', 'url' => $this->image_url, 'poster' => null];
        }

        $media = $this->relationLoaded('media') ? $this->media : $this->media()->get();

        foreach ($media as $item) {
            $items[] = [
                'id' => 'm' . $item->id,
                'type' => $item->type,
                'url' => $item->url,
                // Video: the main image is shown before it plays
                'poster' => $item->isVideo() ? $this->image_url : null,
            ];
        }

        return $items;
    }
}

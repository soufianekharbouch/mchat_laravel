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
}

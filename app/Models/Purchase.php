<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A customer buys a product (pack of recipes) for one animal.
 *
 * The product rules are copied on the purchase (snapshot), so later
 * changes to the product never change what the customer bought.
 */
class Purchase extends Model
{
    /*
    |--------------------------------------------------------------------------
    | STATUSES
    |--------------------------------------------------------------------------
    */

    public const STATUSES = [
        'pending' => ['label' => 'Pending payment', 'label_ar' => 'بانتظار الدفع', 'color' => 'warning'],
        'paid' => ['label' => 'Paid', 'label_ar' => 'مدفوع', 'color' => 'info'],
        'active' => ['label' => 'Active', 'label_ar' => 'نشط', 'color' => 'success'],
        'completed' => ['label' => 'Completed', 'label_ar' => 'مكتمل', 'color' => 'secondary'],
        'expired' => ['label' => 'Expired', 'label_ar' => 'منتهي', 'color' => 'dark'],
        'cancelled' => ['label' => 'Cancelled', 'label_ar' => 'ملغى', 'color' => 'danger'],
        'refunded' => ['label' => 'Refunded', 'label_ar' => 'مسترد', 'color' => 'danger'],
    ];

    /**
     * Allowed status changes.
     */
    public const TRANSITIONS = [
        'pending' => ['paid', 'cancelled'],
        'paid' => ['active', 'cancelled', 'refunded'],
        'active' => ['completed', 'expired', 'cancelled', 'refunded'],
        'completed' => ['refunded'],
        'expired' => ['active', 'refunded'],
        'cancelled' => ['pending'],
        'refunded' => [],
    ];

    /**
     * Statuses for which a note is required (reason).
     */
    public const STATUSES_REQUIRING_NOTE = ['cancelled', 'refunded'];

    /**
     * Purchases that count as "bought" (product purchase limits).
     */
    public const COUNTED_STATUSES = ['pending', 'paid', 'active', 'completed', 'expired'];

    public const SOURCES = [
        'admin' => 'Back-office',
        'app' => 'Mobile app',
        'shopify' => 'Shopify',
    ];

    public const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'knet' => 'KNET',
        'card' => 'Credit card',
        'bank_transfer' => 'Bank transfer',
        'shopify' => 'Shopify',
        'free' => 'Free',
        'other' => 'Other',
    ];

    protected $fillable = [
        'reference',

        'customer_account_id',
        'subscription_id',
        'customer_name',
        'customer_phone',

        'animal_id',
        'animal_name',

        'product_id',
        'product_code',
        'product_name',
        'product_name_ar',
        'species',
        'product_type',
        'premium_status',
        'premium_original_product_id',
        'premium_original_product_code',
        'premium_processed_at',
        'premium_processed_by',
        'is_trial',
        'duration_value',
        'duration_unit',
        'total_recipes',
        'min_deliveries',
        'max_deliveries',
        'min_recipes_per_delivery',
        'max_recipes_per_delivery',
        'min_days_between_deliveries',

        'unit_price',
        'discount_amount',
        'total_amount',
        'price_source',
        'trial_purchase_id',
        'currency',

        'source',
        'payment_method',
        'payment_reference',
        'payment_proof',
        'paid_at',

        'shopify_order_id',
        'shopify_order_name',

        'purchased_at',
        'starts_on',
        'ends_on',
        'status',
        'status_changed_at',

        'customer_note',
        'admin_note',

        'created_by_user_id',
    ];

    protected $casts = [
        'is_trial' => 'boolean',

        'duration_value' => 'integer',
        'total_recipes' => 'integer',
        'min_deliveries' => 'integer',
        'max_deliveries' => 'integer',
        'min_recipes_per_delivery' => 'integer',
        'max_recipes_per_delivery' => 'integer',
        'min_days_between_deliveries' => 'integer',

        'unit_price' => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'total_amount' => 'decimal:3',

        'paid_at' => 'datetime',
        'purchased_at' => 'datetime',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'status_changed_at' => 'datetime',
        'premium_processed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        /*
         * PREMIUM-... pack: always "untreated" when bought (app, Shopify,
         * back-office), even for a customer who already had one.
         */
        static::creating(function (Purchase $purchase) {
            if ($purchase->premium_status === null && \App\Support\PremiumPack::isPremiumCode($purchase->product_code)) {
                $purchase->premium_status = \App\Support\PremiumPack::PENDING;
            }
        });

        /*
         * Readable reference once the id is known: PUR-000123.
         */
        static::created(function (Purchase $purchase) {
            if (!$purchase->reference) {
                $purchase->reference = 'PUR-' . str_pad((string) $purchase->id, 6, '0', STR_PAD_LEFT);
                $purchase->saveQuietly();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    /**
     * Trial purchase that gave the special "trial offer" price.
     */
    public function trialPurchase(): BelongsTo
    {
        return $this->belongsTo(self::class, 'trial_purchase_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function premiumProcessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'premium_processed_by');
    }

    public function premiumOriginalProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'premium_original_product_id')->withTrashed();
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(PurchaseStatusHistory::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PurchaseDelivery::class)
            ->orderBy('delivery_date');
    }

    public function deliveryItems(): HasManyThrough
    {
        return $this->hasManyThrough(PurchaseDeliveryItem::class, PurchaseDelivery::class);
    }

    /*
    |--------------------------------------------------------------------------
    | PRODUCT SNAPSHOT
    |--------------------------------------------------------------------------
    */

    /**
     * Copies the product rules and its current price (promo included).
     */
    public function fillFromProduct(Product $product): self
    {
        $this->fill([
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'product_name_ar' => $product->name_ar,
            'species' => $product->species,
            'product_type' => $product->product_type,
            'is_trial' => $product->is_trial,
            'duration_value' => $product->duration_value,
            'duration_unit' => $product->duration_unit,
            'total_recipes' => $product->total_recipes,
            'min_deliveries' => $product->min_deliveries,
            'max_deliveries' => $product->max_deliveries,
            'min_recipes_per_delivery' => $product->min_recipes_per_delivery,
            'max_recipes_per_delivery' => $product->max_recipes_per_delivery,
            'min_days_between_deliveries' => $product->min_days_between_deliveries,
            'unit_price' => $product->currentPrice(),
            'price_source' => $product->hasActivePromo() ? 'promo' : 'regular',
            'currency' => $product->currency,
        ]);

        return $this;
    }

    /**
     * Last day covered by the pack when it starts on $start.
     */
    public function computeEndDate(Carbon|string $start): Carbon
    {
        return (new Product([
            'duration_value' => $this->duration_value,
            'duration_unit' => $this->duration_unit,
        ]))->endDateFrom($start);
    }

    /*
    |--------------------------------------------------------------------------
    | STATUS
    |--------------------------------------------------------------------------
    */

    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * Changes the status and writes the history line.
     *
     * $changedByType: admin | customer | system | shopify
     */
    public function changeStatus(
        string $toStatus,
        ?string $note = null,
        string $changedByType = 'admin',
        ?int $userId = null
    ): self {
        if (!array_key_exists($toStatus, self::STATUSES)) {
            throw new InvalidArgumentException("Unknown purchase status [{$toStatus}].");
        }

        if (!$this->canTransitionTo($toStatus)) {
            throw new InvalidArgumentException(
                "A {$this->status} purchase cannot become {$toStatus}."
            );
        }

        DB::transaction(function () use ($toStatus, $note, $changedByType, $userId) {
            $fromStatus = $this->status;

            $this->status = $toStatus;
            $this->status_changed_at = now();

            // Paid now: payment date filled if missing
            if ($toStatus === 'paid' && !$this->paid_at) {
                $this->paid_at = now();
            }

            // Activated: the pack period starts (today by default)
            if ($toStatus === 'active') {
                if (!$this->starts_on) {
                    $this->starts_on = Carbon::today();
                }

                if (!$this->ends_on) {
                    $this->ends_on = $this->computeEndDate($this->starts_on);
                }
            }

            $this->save();

            $this->recordStatusHistory($fromStatus, $toStatus, $note, $changedByType, $userId);
        });

        return $this;
    }

    /**
     * History line (also used for the creation: from_status = null).
     */
    public function recordStatusHistory(
        ?string $fromStatus,
        string $toStatus,
        ?string $note = null,
        string $changedByType = 'admin',
        ?int $userId = null
    ): PurchaseStatusHistory {
        return $this->statusHistories()->create([
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'changed_by_type' => $changedByType,
            'changed_by_user_id' => $userId,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RECIPES USAGE
    |--------------------------------------------------------------------------
    */

    /**
     * Recipes already placed in deliveries that are not cancelled.
     */
    public function scheduledRecipesCount(): int
    {
        return (int) $this->deliveryItems()
            ->where('purchase_deliveries.status', '!=', 'cancelled')
            ->sum('purchase_delivery_items.quantity');
    }

    public function remainingRecipesCount(): int
    {
        return max(0, $this->total_recipes - $this->scheduledRecipesCount());
    }

    /*
    |--------------------------------------------------------------------------
    | DISPLAY
    |--------------------------------------------------------------------------
    */

    public static function statusLabel(?string $status, string $lang = 'en'): string
    {
        $config = self::STATUSES[$status] ?? null;

        if (!$config) {
            return (string) $status;
        }

        return $lang === 'ar' ? $config['label_ar'] : $config['label'];
    }

    public static function statusColor(?string $status): string
    {
        return self::STATUSES[$status]['color'] ?? 'secondary';
    }

    public function localizedProductName(string $lang = 'en'): string
    {
        return $lang === 'ar' && filled($this->product_name_ar)
            ? $this->product_name_ar
            : $this->product_name;
    }

    public function durationLabel(string $lang = 'en'): string
    {
        return (new Product([
            'duration_value' => $this->duration_value,
            'duration_unit' => $this->duration_unit,
        ]))->durationLabel($lang);
    }

    public function getPaymentProofUrlAttribute(): ?string
    {
        return $this->payment_proof ? asset('storage/app/public/' . $this->payment_proof) : null;
    }

    public function getPaymentMethodLabelAttribute(): ?string
    {
        return $this->payment_method
            ? (self::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method)
            : null;
    }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCES[$this->source] ?? (string) $this->source;
    }
}

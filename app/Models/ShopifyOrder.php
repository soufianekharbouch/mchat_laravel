<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shopify order received by webhook ("Auto subscription" menu).
 */
class ShopifyOrder extends Model
{
    public const STATUSES = [
        'pending' => ['label' => 'Waiting payment', 'color' => 'secondary', 'icon' => 'fa-hourglass-half'],
        'processed' => ['label' => 'Done', 'color' => 'success', 'icon' => 'fa-check-circle'],
        'needs_review' => ['label' => 'To check', 'color' => 'warning', 'icon' => 'fa-exclamation-triangle'],
        'failed' => ['label' => 'Nothing done', 'color' => 'danger', 'icon' => 'fa-times-circle'],
        'ignored' => ['label' => 'Ignored', 'color' => 'dark', 'icon' => 'fa-ban'],
    ];

    protected $fillable = [
        'shopify_order_id',
        'shopify_order_name',
        'shopify_customer_id',
        'topic',
        'financial_status',
        'total_price',
        'currency',
        'ordered_at',
        'first_name',
        'last_name',
        'phone_raw',
        'phone',
        'province_raw',
        'address_raw',
        'province_id',
        'zone_id',
        'line_items',
        'status',
        'issues',
        'customer_account_id',
        'subscription_id',
        'customer_created',
        'purchases_count',
        'attempts',
        'processed_at',
        'reviewed_at',
        'reviewed_by_user_id',
        'admin_note',
        'payload',
    ];

    protected $casts = [
        'line_items' => 'array',
        'issues' => 'array',
        'total_price' => 'decimal:3',
        'customer_created' => 'boolean',
        'ordered_at' => 'datetime',
        'processed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** Purchases created from this order (purchases.shopify_order_id). */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'shopify_order_id', 'shopify_order_id');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function payloadArray(): array
    {
        $data = json_decode((string) $this->payload, true);

        return is_array($data) ? $data : [];
    }

    public function customerName(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')) ?: '-';
    }

    public function statusConfig(): array
    {
        return self::STATUSES[$this->status] ?? ['label' => $this->status, 'color' => 'secondary', 'icon' => 'fa-circle'];
    }

    public function errors(): array
    {
        return array_values(array_filter($this->issues ?? [], fn ($issue) => ($issue['level'] ?? '') === 'error'));
    }

    public function warnings(): array
    {
        return array_values(array_filter($this->issues ?? [], fn ($issue) => ($issue['level'] ?? '') !== 'error'));
    }

    /** New orders not reviewed yet (badge of the menu). */
    public static function unreviewedCount(): int
    {
        try {
            return static::query()
                ->whereNull('reviewed_at')
                ->where('status', '!=', 'ignored')
                ->count();
        } catch (\Throwable) {
            return 0; // table not created yet
        }
    }
}

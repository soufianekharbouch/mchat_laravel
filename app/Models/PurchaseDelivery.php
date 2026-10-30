<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One delivery chosen by the customer inside a purchase.
 */
class PurchaseDelivery extends Model
{
    public const STATUSES = [
        'scheduled' => ['label' => 'Scheduled', 'color' => 'primary'],
        'prepared' => ['label' => 'Prepared', 'color' => 'warning'],
        'delivered' => ['label' => 'Delivered', 'color' => 'success'],
        'cancelled' => ['label' => 'Cancelled', 'color' => 'secondary'],
    ];

    protected $fillable = [
        'purchase_id',
        'delivery_number',
        'delivery_date',
        'delivery_slot',
        'customer_address_id',
        'status',
        'planned_order_id',
    ];

    protected $casts = [
        'delivery_number' => 'integer',
        'delivery_date' => 'date',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function customerAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseDeliveryItem::class);
    }

    public function plannedOrder(): BelongsTo
    {
        return $this->belongsTo(PlannedOrder::class);
    }

    public function recipesCount(): int
    {
        return (int) $this->items->sum('quantity');
    }
}

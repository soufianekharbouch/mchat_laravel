<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recipe and quantity of one purchase delivery.
 */
class PurchaseDeliveryItem extends Model
{
    protected $fillable = [
        'purchase_delivery_id',
        'recipe_id',
        'recipe_name',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(PurchaseDelivery::class, 'purchase_delivery_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}

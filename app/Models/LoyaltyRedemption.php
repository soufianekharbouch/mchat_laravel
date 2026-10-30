<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Points of a pet used as a discount on one pack (one per purchase).
 * Purchase cancelled / refunded: the points come back automatically.
 */
class LoyaltyRedemption extends Model
{
    protected $table = 'loyalty_redemptions';

    protected $fillable = [
        'animal_id',
        'purchase_id',
        'points',
        'amount',
        'note',
        'created_by',
    ];

    protected $casts = [
        'points' => 'integer',
        'amount' => 'decimal:3',
    ];

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}

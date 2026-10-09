<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlannedOrderItem extends Model
{
    protected $fillable = ['planned_order_id', 'recipe_id', 'quantity'];

    protected $casts = ['quantity' => 'integer'];

    public function order()
    {
        return $this->belongsTo(PlannedOrder::class, 'planned_order_id');
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }
}

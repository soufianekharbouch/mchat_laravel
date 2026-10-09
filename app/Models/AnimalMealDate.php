<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnimalMealDate extends Model
{
    use HasFactory;

    protected $fillable = [
        'animal_id',
        'recipe_id',
        'meal_date',
        'quantity',
    ];

    protected $casts = [
        'meal_date' => 'date',
        'quantity' => 'integer',
    ];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }
}

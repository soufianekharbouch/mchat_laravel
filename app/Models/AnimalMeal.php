<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnimalMeal extends Model
{
    use HasFactory;

    protected $fillable = [
        'animal_id',
        'recipe_id',
        'day_of_week',
        'quantity',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'species',
        'breed_size_category',
        'created_by',
        'is_premium',
        'recipe_type_id',
        'life_stage',
        'composition',
    ];

    protected $casts = [
        'is_premium' => 'boolean',
        'composition' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ingredients()
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function recipeType()
    {
        return $this->belongsTo(RecipeType::class, 'recipe_type_id');
    }
}
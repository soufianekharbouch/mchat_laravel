<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'unit', 'created_by', 'yield_percentage'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipeIngredients()
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function isUsedInRecipes()
    {
        return $this->recipeIngredients()->exists();
    }

    public function getRecipesUsingThis()
    {
        return $this->recipeIngredients()->with('recipe')->get()->pluck('recipe');
    }
    public function stocks()
    {
        return $this->hasMany(\App\Models\IngredientStock::class);
    }


}

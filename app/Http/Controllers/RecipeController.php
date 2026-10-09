<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\Ingredient;
use App\Models\RecipeIngredient;
use App\Models\AnimalMeal;
use App\Models\RecipeType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class RecipeController extends Controller
{
    public function index()
    {
        $recipeTypes = RecipeType::orderBy('name')
            ->with(['recipes' => function ($q) {
                $q->with(['creator', 'ingredients'])
                  ->orderBy('name');
            }])
            ->get();

        $untypedRecipes = Recipe::whereNull('recipe_type_id')
            ->with(['creator', 'ingredients'])
            ->orderBy('name')
            ->get();

        return view('recipes.index', compact('recipeTypes', 'untypedRecipes'));
    }

    public function create()
    {
        $ingredients = Ingredient::all();
        $recipeTypes = RecipeType::orderBy('name')->get();

        return view('recipes.create', compact('ingredients', 'recipeTypes'));
    }

    public function store(Request $request)
    {
        $isPremium = $request->boolean('is_premium');

        $rules = [
            'code' => 'required|unique:recipes,code',
            'name' => 'required',
            'species' => 'required|in:Cat,Dog',
            'breed_size_category' => 'nullable|in:Small,Medium,Large',
            'ingredients' => 'required|array',
            'quantities' => 'required|array',
            'recipe_type_id' => 'nullable|exists:recipe_types,id',
            'life_stage' => 'nullable|in:Kitten,Puppy,Adult,Senior,All',
        ];

        if ($isPremium) {
            $rules['composition_labels'] = 'required|array';
            $rules['composition_values'] = 'required|array';
        }

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request) {
            if ($request->input('species') === 'Dog' && !$request->filled('breed_size_category')) {
                $validator->errors()->add('breed_size_category', 'The breed size category field is required when species is Dog.');
            }
        });

        $validated = $validator->validate();

        $composition = [];
        $labels = $request->input('composition_labels', []);
        $values = $request->input('composition_values', []);

        foreach ($labels as $index => $label) {
            $value = $values[$index] ?? '';
            $label = trim((string) $label);
            $value = trim((string) $value);
            if ($label !== '' || $value !== '') {
                $composition[] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        $species = $validated['species'];
        $breedSizeCategory = $species === 'Dog' ? ($validated['breed_size_category'] ?? null) : null;

        $recipe = Recipe::create([
            'code' => $request->code,
            'name' => $request->name,
            'species' => $species,
            'breed_size_category' => $breedSizeCategory,
            'created_by' => Auth::id(),
            'is_premium' => $isPremium,
            'composition' => $composition,
            'recipe_type_id' => $validated['recipe_type_id'] ?? null,
            'life_stage' => $validated['life_stage'] ?? null,
        ]);

        foreach ($request->ingredients as $key => $ingredientId) {
            $quantity = $request->quantities[$key] ?? null;
            if ($ingredientId && $quantity) {
                RecipeIngredient::create([
                    'recipe_id' => $recipe->id,
                    'ingredient_id' => $ingredientId,
                    'quantity' => $quantity,
                ]);
            }
        }

        return redirect()->route('recipes.index')->with('success', 'Recipe created successfully.');
    }

    public function edit(Recipe $recipe)
    {
        $ingredients = Ingredient::all();
        $recipeTypes = RecipeType::orderBy('name')->get();

        $recipe->load('ingredients');

        return view('recipes.edit', compact('recipe', 'ingredients', 'recipeTypes'));
    }

    public function update(Request $request, Recipe $recipe)
    {
        $isPremium = $request->boolean('is_premium');

        $rules = [
            'code' => 'required|unique:recipes,code,' . $recipe->id,
            'name' => 'required',
            'species' => 'required|in:Cat,Dog',
            'breed_size_category' => 'nullable|in:Small,Medium,Large',
            'ingredients' => 'required|array',
            'quantities' => 'required|array',
            'recipe_type_id' => 'nullable|exists:recipe_types,id',
            'life_stage' => 'nullable|in:Kitten,Puppy,Adult,Senior,All',
        ];

        if ($isPremium) {
            $rules['composition_labels'] = 'required|array';
            $rules['composition_values'] = 'required|array';
        }

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request) {
            if ($request->input('species') === 'Dog' && !$request->filled('breed_size_category')) {
                $validator->errors()->add('breed_size_category', 'The breed size category field is required when species is Dog.');
            }
        });

        $validated = $validator->validate();

        $composition = [];
        $labels = $request->input('composition_labels', []);
        $values = $request->input('composition_values', []);

        foreach ($labels as $index => $label) {
            $value = $values[$index] ?? '';
            $label = trim((string) $label);
            $value = trim((string) $value);
            if ($label !== '' || $value !== '') {
                $composition[] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        $species = $validated['species'];
        $breedSizeCategory = $species === 'Dog' ? ($validated['breed_size_category'] ?? null) : null;

        $recipe->update([
            'code' => $request->code,
            'name' => $request->name,
            'species' => $species,
            'breed_size_category' => $breedSizeCategory,
            'is_premium' => $isPremium,
            'composition' => $composition,
            'recipe_type_id' => $validated['recipe_type_id'] ?? null,
            'life_stage' => $validated['life_stage'] ?? null,
        ]);

        $recipe->ingredients()->delete();

        foreach ($request->ingredients as $key => $ingredientId) {
            $quantity = $request->quantities[$key] ?? null;
            if ($ingredientId && $quantity) {
                RecipeIngredient::create([
                    'recipe_id' => $recipe->id,
                    'ingredient_id' => $ingredientId,
                    'quantity' => $quantity,
                ]);
            }
        }

        return redirect()->route('recipes.index')->with('success', 'Recipe updated successfully.');
    }

    public function destroy(Recipe $recipe)
    {
        if ($this->isRecipeUsedInSubscriptions($recipe->id)) {
            return redirect()->route('recipes.index')
                ->with('error', 'Cannot delete recipe because it is used in one or more subscriptions.');
        }

        $recipe->delete();

        return redirect()->route('recipes.index')->with('success', 'Recipe deleted successfully.');
    }

    public function show(Recipe $recipe)
    {
        $recipe->load('ingredients.ingredient');
        return view('recipes.show', compact('recipe'));
    }

    private function isRecipeUsedInSubscriptions($recipeId)
    {
        return AnimalMeal::where('recipe_id', $recipeId)->exists();
    }
}
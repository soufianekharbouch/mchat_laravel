<?php

namespace App\Http\Controllers;

use App\Models\RecipeType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecipeTypeController extends Controller
{
    public function index()
    {
        $recipeTypes = RecipeType::orderBy('name')->get();
        return view('recipe-types.index', compact('recipeTypes'));
    }

    public function create()
    {
        return view('recipe-types.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:recipe_types,name',
        ]);

        RecipeType::create([
            'name' => $request->name,
            'created_by' => Auth::id(),
        ]);

        return redirect()
            ->route('recipe-types.index')
            ->with('success', 'Recipe type created successfully.');
    }

    public function edit(RecipeType $recipeType)
    {
        return view('recipe-types.edit', compact('recipeType'));
    }

    public function update(Request $request, RecipeType $recipeType)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:recipe_types,name,' . $recipeType->id,
        ]);

        $recipeType->update([
            'name' => $request->name,
        ]);

        return redirect()
            ->route('recipe-types.index')
            ->with('success', 'Recipe type updated successfully.');
    }

    public function destroy(RecipeType $recipeType)
    {
        // empêcher la suppression si des recettes l’utilisent
        if ($recipeType->recipes()->exists()) {
            return redirect()
                ->route('recipe-types.index')
                ->with('error', 'Cannot delete this type because it is used in one or more recipes.');
        }

        $recipeType->delete();

        return redirect()
            ->route('recipe-types.index')
            ->with('success', 'Recipe type deleted successfully.');
    }
}

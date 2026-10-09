<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\RecipeIngredient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IngredientController extends Controller
{
    public function index()
    {
        $ingredients = Ingredient::with('creator')->get();
        return view('ingredients.index', compact('ingredients'));
    }

    public function create()
    {
        abort_unless(
            auth()->check()
            && auth()->user()->hasPermission('ingredients.create'),
            403,
            'You do not have permission to create ingredients.'
        );
    
        return view('ingredients.create');
    }

    public function store(Request $request)
    {
        abort_unless(
            Auth::check() && Auth::user()->hasPermission('ingredients.create'),
            403,
            'You do not have permission to create ingredients.'
        );
    
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:255',
                'unique:ingredients,code',
            ],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'unit' => [
                'required',
                'in:gram,unit,ml,kg',
            ],
            'yield_percentage' => [
                'nullable',
                'numeric',
                'min:0',
                'max:10000',
            ],
        ]);
    
        $validated['created_by'] = Auth::id();
    
        Ingredient::create($validated);
    
        return redirect()
            ->route('ingredients.index')
            ->with('success', 'Ingredient created successfully.');
    }

    public function edit(Ingredient $ingredient)
    {
        return view('ingredients.edit', compact('ingredient'));
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $request->validate([
            'code' => 'required|unique:ingredients,code,' . $ingredient->id,
            'name' => 'required',
            'unit' => 'required',
            'yield_percentage' => 'nullable|numeric|min:0|max:10000',
        ]);

        $ingredient->update([
            'code' => $request->code,
            'name' => $request->name,
            'unit' => $request->unit,
            'yield_percentage' => $request->yield_percentage,
        ]);

        return redirect()->route('ingredients.index')->with('success', 'Ingredient updated successfully.');
    }

    public function destroy(Ingredient $ingredient)
    {
        $usedInRecipes = RecipeIngredient::where('ingredient_id', $ingredient->id)->exists();

        if ($usedInRecipes) {
            return redirect()->route('ingredients.index')
                ->with('error', 'Cannot delete ingredient because it is used in one or more recipes.');
        }

        $ingredient->delete();

        return redirect()->route('ingredients.index')->with('success', 'Ingredient deleted successfully.');
    }
}

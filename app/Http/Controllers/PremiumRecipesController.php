<?php

namespace App\Http\Controllers;

use App\Models\Province;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PremiumRecipesController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->input('date', Carbon::today()->toDateString());
        $carbonDate = Carbon::parse($date);
        $dayName = strtolower($carbonDate->format('l'));

        $provincesDb = Province::with('zones')->orderBy('name')->get();

        $subscriptions = Subscription::with([
            'animals.meals.recipe.ingredients.ingredient'
        ])->get();

        $recipes = [];
        $recipeCounts = [];

        foreach ($subscriptions as $subscription) {
            foreach ($subscription->animals as $animal) {
                $start = $animal->subscription_start;
                $end = $animal->subscription_end;

                $isActive = false;

                if ($start && $end) {
                    $isActive = $carbonDate->between(
                        $start->copy()->startOfDay(),
                        $end->copy()->endOfDay()
                    );
                } elseif ($start && !$end) {
                    $isActive = $start->copy()->startOfDay()->lte($carbonDate);
                } elseif (!$start && $end) {
                    $isActive = $carbonDate->lte($end->copy()->endOfDay());
                } else {
                    $isActive = true;
                }

                if (!$isActive) continue;

                foreach ($animal->meals as $meal) {
                    if (strtolower((string)$meal->day_of_week) !== $dayName) continue;
                    if (!$meal->recipe) continue;
                    if (empty($meal->recipe->is_premium)) continue;

                    $qty = (int)($meal->quantity ?? 0);
                    if ($qty <= 0) continue;

                    $recipeId = $meal->recipe->id;

                    if (!isset($recipeCounts[$recipeId])) $recipeCounts[$recipeId] = 0;
                    $recipeCounts[$recipeId] += $qty;

                    $recipes[$recipeId] = $meal->recipe;
                }
            }
        }

        // Calcul ingrédients "raw" pour premium seulement
        $ingredientPerRecipe = [];

        foreach ($recipes as $recipeId => $recipe) {
            $ingredientPerRecipe[$recipeId] = [];
            $portionCount = (int)($recipeCounts[$recipeId] ?? 0);
            if ($portionCount <= 0) continue;

            foreach ($recipe->ingredients as $recipeIngredient) {
                $ingredient = $recipeIngredient->ingredient;
                if (!$ingredient) continue;

                $finalNeeded = (float)$recipeIngredient->quantity * (float)$portionCount;

                $yieldPercent = $ingredient->yield_percentage;
                if ($yieldPercent === null || (float)$yieldPercent <= 0) $yieldPercent = 100;

                $yieldFactor = (float)$yieldPercent / 100.0;
                if ($yieldFactor <= 0) $yieldFactor = 1;

                $rawNeeded = $finalNeeded / $yieldFactor;

                if (!isset($ingredientPerRecipe[$recipeId][$ingredient->id])) {
                    $ingredientPerRecipe[$recipeId][$ingredient->id] = [
                        'ingredient' => $ingredient,
                        'total_quantity' => 0,
                    ];
                }

                $ingredientPerRecipe[$recipeId][$ingredient->id]['total_quantity'] += $rawNeeded;
            }
        }

        // Tri par nom
        uasort($recipes, function ($a, $b) {
            return strcmp($a->name ?? '', $b->name ?? '');
        });

        return view('daily-report.premium-recipes', [
            'date' => $date,
            'recipes' => $recipes,
            'recipeCounts' => $recipeCounts,
            'ingredientPerRecipe' => $ingredientPerRecipe,
            'provincesDb' => $provincesDb,
        ]);
    }
}

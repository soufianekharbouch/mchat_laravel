<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Subscription;
use App\Models\RecipeIngredient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Mpdf\Mpdf;

class DailyReportPdfController extends Controller
{
    public function deliveryPdf(Subscription $subscription, Request $request)
    {
        $date = (string) $request->input('date');
        $carbonDate = Carbon::parse($date)->startOfDay();
        $dateKey = $carbonDate->format('Y-m-d');

        $subscription->load('animals.mealDates.recipe');

        $animalsForDay = [];

        foreach ($subscription->animals as $animal) {
            $start = $animal->subscription_start ? Carbon::parse($animal->subscription_start)->startOfDay() : null;
            $end   = $animal->subscription_end ? Carbon::parse($animal->subscription_end)->endOfDay() : null;

            $isActive = true;
            if ($start && $end) $isActive = $carbonDate->between($start, $end);
            elseif ($start && !$end) $isActive = $carbonDate->gte($start);
            elseif (!$start && $end) $isActive = $carbonDate->lte($end);

            if (!$isActive) continue;

            $mealsForDay = $animal->mealDates->filter(function ($mealDate) use ($dateKey) {
                $md = $mealDate->meal_date ? Carbon::parse($mealDate->meal_date)->format('Y-m-d') : null;
                return $md === $dateKey
                    && ((int) ($mealDate->quantity ?? 0)) > 0
                    && $mealDate->recipe;
            });

            if ($mealsForDay->isNotEmpty()) {
                $animalsForDay[] = [
                    'animal' => $animal,
                    'meals'  => $mealsForDay,
                ];
            }
        }

        $settings = Setting::instance();

        $html = view('daily-report.pdf-delivery', [
            'subscription'  => $subscription,
            'date'          => $dateKey,
            'animalsForDay' => $animalsForDay,
            'settings'      => $settings,
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'default_font' => 'dejavusans',
            'directionality' => (($settings->pdf_lang ?? 'en') === 'ar') ? 'rtl' : 'ltr',
        ]);

        $mpdf->WriteHTML($html);

        return response(
            $mpdf->Output('', 'S'),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="delivery-' . $subscription->code . '-' . $dateKey . '.pdf"',
            ]
        );
    }

    public function premiumPdf(Subscription $subscription, Request $request)
    {
        $date = (string) $request->input('date');
        $carbonDate = Carbon::parse($date)->startOfDay();
        $dateKey = $carbonDate->format('Y-m-d');

        $subscription->load('animals.mealDates.recipe');

        $animalsForDay = [];

        foreach ($subscription->animals as $animal) {
            $start = $animal->subscription_start ? Carbon::parse($animal->subscription_start)->startOfDay() : null;
            $end   = $animal->subscription_end ? Carbon::parse($animal->subscription_end)->endOfDay() : null;

            $isActive = true;
            if ($start && $end) $isActive = $carbonDate->between($start, $end);
            elseif ($start && !$end) $isActive = $carbonDate->gte($start);
            elseif (!$start && $end) $isActive = $carbonDate->lte($end);

            if (!$isActive) continue;

            $mealsForDay = $animal->mealDates->filter(function ($mealDate) use ($dateKey) {
                $md = $mealDate->meal_date ? Carbon::parse($mealDate->meal_date)->format('Y-m-d') : null;
                return $md === $dateKey
                    && ((int) ($mealDate->quantity ?? 0)) > 0
                    && $mealDate->recipe
                    && (bool) ($mealDate->recipe->is_premium ?? false);
            });

            if ($mealsForDay->isNotEmpty()) {
                $animalsForDay[] = [
                    'animal' => $animal,
                    'meals'  => $mealsForDay,
                ];
            }
        }

        $settings = Setting::instance();

        $html = view('daily-report.pdf-premium', [
            'subscription'  => $subscription,
            'date'          => $dateKey,
            'animalsForDay' => $animalsForDay,
            'settings'      => $settings,
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'default_font' => 'dejavusans',
            'directionality' => (($settings->pdf_lang ?? 'en') === 'ar') ? 'rtl' : 'ltr',
        ]);

        $mpdf->WriteHTML($html);

        return response(
            $mpdf->Output('', 'S'),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="premium-' . $subscription->code . '-' . $dateKey . '.pdf"',
            ]
        );
    }

    public function dailyReportPdf(Request $request)
    {
        $date = (string) $request->input('date');
        $carbonDate = Carbon::parse($date)->startOfDay();
        $dateKey = $carbonDate->format('Y-m-d');

        $subscriptions = Subscription::with([
            'animals.mealDates.recipe',
        ])->get();

        $subscriptions = $subscriptions->filter(function ($subscription) use ($carbonDate, $dateKey) {
            foreach ($subscription->animals as $animal) {
                $start = $animal->subscription_start ? Carbon::parse($animal->subscription_start)->startOfDay() : null;
                $end   = $animal->subscription_end ? Carbon::parse($animal->subscription_end)->endOfDay() : null;

                $isActive = true;
                if ($start && $end) $isActive = $carbonDate->between($start, $end);
                elseif ($start && !$end) $isActive = $carbonDate->gte($start);
                elseif (!$start && $end) $isActive = $carbonDate->lte($end);

                if (!$isActive) continue;

                $hasMeals = $animal->mealDates->contains(function ($mealDate) use ($dateKey) {
                    $md = $mealDate->meal_date ? Carbon::parse($mealDate->meal_date)->format('Y-m-d') : null;
                    return $md === $dateKey
                        && ((int) ($mealDate->quantity ?? 0)) > 0
                        && $mealDate->recipe;
                });

                if ($hasMeals) return true;
            }
            return false;
        })->values();

        $recipeCounts = [];
        $recipes = [];
        $usedRecipeIds = [];

        foreach ($subscriptions as $subscription) {
            foreach ($subscription->animals as $animal) {
                $start = $animal->subscription_start ? Carbon::parse($animal->subscription_start)->startOfDay() : null;
                $end   = $animal->subscription_end ? Carbon::parse($animal->subscription_end)->endOfDay() : null;

                $isActive = true;
                if ($start && $end) $isActive = $carbonDate->between($start, $end);
                elseif ($start && !$end) $isActive = $carbonDate->gte($start);
                elseif (!$start && $end) $isActive = $carbonDate->lte($end);

                if (!$isActive) continue;

                foreach ($animal->mealDates as $mealDate) {
                    $md = $mealDate->meal_date ? Carbon::parse($mealDate->meal_date)->format('Y-m-d') : null;
                    if ($md !== $dateKey) continue;

                    $qty = (int) ($mealDate->quantity ?? 0);
                    if ($qty <= 0) continue;

                    if (!$mealDate->recipe) continue;

                    $rid = (int) $mealDate->recipe->id;

                    $usedRecipeIds[$rid] = true;
                    $recipes[$rid] = $mealDate->recipe;

                    if (!isset($recipeCounts[$rid])) $recipeCounts[$rid] = 0;
                    $recipeCounts[$rid] += $qty;
                }
            }
        }

        $usedRecipeIds = array_keys($usedRecipeIds);

        $recipeIngredientMap = [];

        if (!empty($usedRecipeIds)) {
            $rows = RecipeIngredient::with('ingredient')
                ->whereIn('recipe_id', $usedRecipeIds)
                ->get();

            foreach ($rows as $ri) {
                if (!$ri->ingredient) continue;

                $rid = (int) $ri->recipe_id;
                $iid = (int) $ri->ingredient_id;

                if (!isset($recipeIngredientMap[$rid])) $recipeIngredientMap[$rid] = [];
                $recipeIngredientMap[$rid][$iid] = [
                    'ingredient' => $ri->ingredient,
                    'qty_per_recipe' => (float) $ri->quantity,
                ];
            }
        }

        $ingredientTotalsTmp = [];

        foreach ($subscriptions as $subscription) {
            foreach ($subscription->animals as $animal) {
                $start = $animal->subscription_start ? Carbon::parse($animal->subscription_start)->startOfDay() : null;
                $end   = $animal->subscription_end ? Carbon::parse($animal->subscription_end)->endOfDay() : null;

                $isActive = true;
                if ($start && $end) $isActive = $carbonDate->between($start, $end);
                elseif ($start && !$end) $isActive = $carbonDate->gte($start);
                elseif (!$start && $end) $isActive = $carbonDate->lte($end);

                if (!$isActive) continue;

                foreach ($animal->mealDates as $mealDate) {
                    $md = $mealDate->meal_date ? Carbon::parse($mealDate->meal_date)->format('Y-m-d') : null;
                    if ($md !== $dateKey) continue;

                    $mealQty = (int) ($mealDate->quantity ?? 0);
                    if ($mealQty <= 0) continue;

                    if (!$mealDate->recipe) continue;

                    $rid = (int) $mealDate->recipe->id;
                    if (empty($recipeIngredientMap[$rid])) continue;

                    foreach ($recipeIngredientMap[$rid] as $iid => $data) {
                        $need = $mealQty * (float) ($data['qty_per_recipe'] ?? 0);

                        if (!isset($ingredientTotalsTmp[$rid])) $ingredientTotalsTmp[$rid] = [];
                        if (!isset($ingredientTotalsTmp[$rid][$iid])) $ingredientTotalsTmp[$rid][$iid] = 0;

                        $ingredientTotalsTmp[$rid][$iid] += $need;
                    }
                }
            }
        }

        $ingredientPerRecipe = [];
        foreach ($ingredientTotalsTmp as $rid => $ings) {
            $ingredientPerRecipe[$rid] = [];
            foreach ($ings as $iid => $totalQty) {
                $ingredient = $recipeIngredientMap[$rid][$iid]['ingredient'] ?? null;
                if (!$ingredient) continue;

                $ingredientPerRecipe[$rid][] = [
                    'ingredient' => $ingredient,
                    'total_quantity' => (float) $totalQty,
                ];
            }
        }

        $settings = Setting::instance();

        $html = view('daily-report.pdf-full', [
            'date' => $dateKey,
            'subscriptions' => $subscriptions,
            'recipes' => $recipes,
            'recipeCounts' => $recipeCounts,
            'ingredientPerRecipe' => $ingredientPerRecipe,
            'settings' => $settings,
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'default_font' => 'dejavusans',
            'directionality' => (($settings->pdf_lang ?? 'en') === 'ar') ? 'rtl' : 'ltr',
        ]);

        $mpdf->WriteHTML($html);

        return response(
            $mpdf->Output('', 'S'),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="daily-report-' . $dateKey . '.pdf"',
            ]
        );
    }
}

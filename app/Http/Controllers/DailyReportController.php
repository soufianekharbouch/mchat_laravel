<?php

namespace App\Http\Controllers;

use App\Models\PlannedOrder;
use App\Models\Province;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DailyReportController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->input('date', Carbon::today()->toDateString());
        $carbonDate = Carbon::parse($date)->startOfDay();
        $today = Carbon::today()->startOfDay();

        $settings = Setting::instance();

        $provincesDb = Province::with('zones')->orderBy('name')->get();

        $plannedOrders = PlannedOrder::with([
            'subscription',
            'animal',
            'items.recipe.recipeType',
            'items.recipe.ingredients.ingredient',
        ])
            ->whereDate('scheduled_for', $date)
            ->get();

        foreach ($plannedOrders as $o) {
            $computed = $o->status;
            if ($o->status === 'planned' && Carbon::parse($o->scheduled_for)->startOfDay()->lt($today)) {
                $computed = 'overdue';
            }
            $o->computed_status = $computed;
        }

        $plannedOrdersBySubscription = $plannedOrders->groupBy('subscription_id');

        $subscriptions = $plannedOrders
            ->map(fn($o) => $o->subscription)
            ->filter()
            ->unique('id')
            ->values()
            ->sortBy(function ($sub) {
                return ($sub->subscriber_province ?? '') . ' ' . ($sub->subscriber_zone ?? '');
            })
            ->values();

        $subscriptionFlags = [];

        $subIds = $subscriptions->pluck('id')->filter()->values()->all();

        if (!empty($subIds)) {
            $prevSubs = PlannedOrder::query()
                ->whereIn('subscription_id', $subIds)
                ->whereDate('scheduled_for', '<', $date)
                ->selectRaw('subscription_id, COUNT(*) as cnt')
                ->groupBy('subscription_id')
                ->pluck('cnt', 'subscription_id')
                ->toArray();

            $futureSubs = PlannedOrder::query()
                ->whereIn('subscription_id', $subIds)
                ->whereDate('scheduled_for', '>', $date)
                ->where('status', 'planned')
                ->selectRaw('subscription_id, COUNT(*) as cnt')
                ->groupBy('subscription_id')
                ->pluck('cnt', 'subscription_id')
                ->toArray();

            $daysStats = PlannedOrder::query()
                ->whereIn('subscription_id', $subIds)
                ->selectRaw("
                    subscription_id,
                    COUNT(DISTINCT DATE(scheduled_for)) as days_count,
                    MIN(DATE(scheduled_for)) as first_day,
                    MAX(DATE(scheduled_for)) as last_day
                ")
                ->groupBy('subscription_id')
                ->get()
                ->keyBy('subscription_id');

            foreach ($subIds as $sid) {
                $hasPrev = (int)($prevSubs[$sid] ?? 0) > 0;
                $hasFuturePlanned = (int)($futureSubs[$sid] ?? 0) > 0;

                $stat = $daysStats->get($sid);
                $daysCount = (int)($stat->days_count ?? 0);
                $firstDay = (string)($stat->first_day ?? '');
                $lastDay = (string)($stat->last_day ?? '');

                $isSingleDayHistoryForThisDate = ($daysCount === 1 && $firstDay === $date);

                $isFirst = $isSingleDayHistoryForThisDate ? true : !$hasPrev;
                $isLast = $isSingleDayHistoryForThisDate ? true : !$hasFuturePlanned;

                $subscriptionFlags[$sid] = [
                    'is_first_order' => $isFirst,
                    'is_last_order' => $isLast,
                ];
            }
        }

        $recipeCounts = [];
        $recipes = [];

        foreach ($plannedOrders as $order) {
            foreach (($order->items ?? collect()) as $item) {
                $recipe = $item->recipe;
                if (!$recipe) continue;

                $recipeId = $recipe->id;
                $qty = (int)($item->quantity ?? 0);
                if ($qty <= 0) continue;

                if (!isset($recipeCounts[$recipeId])) $recipeCounts[$recipeId] = 0;

                $recipeCounts[$recipeId] += $qty;
                $recipes[$recipeId] = $recipe;
            }
        }

        $ingredientPerRecipe = [];

        foreach ($recipes as $recipeId => $recipe) {
            $ingredientPerRecipe[$recipeId] = [];
            $portionCount = (int)($recipeCounts[$recipeId] ?? 0);

            if ($portionCount <= 0) continue;

            foreach (($recipe->ingredients ?? collect()) as $recipeIngredient) {
                $ingredient = $recipeIngredient->ingredient ?? null;
                if (!$ingredient) continue;

                $finalNeeded = (float)($recipeIngredient->quantity ?? 0) * (float)$portionCount;

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

        $provinces = $provincesDb->pluck('name')->values()->toArray();

        return view('daily-report.index', [
            'date' => $date,
            'carbonDate' => $carbonDate,
            'plannedOrders' => $plannedOrders,
            'plannedOrdersBySubscription' => $plannedOrdersBySubscription,
            'subscriptions' => $subscriptions,
            'recipeCounts' => $recipeCounts,
            'recipes' => $recipes,
            'ingredientPerRecipe' => $ingredientPerRecipe,
            'provinces' => $provinces,
            'provincesDb' => $provincesDb,
            'settings' => $settings,
            'subscriptionFlags' => $subscriptionFlags,
        ]);
    }

    public function markPrepared(Request $request, PlannedOrder $plannedOrder)
    {
        $plannedOrder->status = 'prepared';
        $plannedOrder->save();

        return response()->json([
            'ok' => true,
            'order_id' => $plannedOrder->id,
            'status' => $plannedOrder->status,
            'subscription_id' => $plannedOrder->subscription_id,
        ]);
    }
}
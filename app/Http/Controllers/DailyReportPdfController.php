<?php

namespace App\Http\Controllers;

use App\Models\PlannedOrder;
use App\Models\PurchaseDelivery;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\RecipeIngredient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Mpdf\Mpdf;

/*
|--------------------------------------------------------------------------
| DAILY REPORT PDFs
|--------------------------------------------------------------------------
|
| Meals of a day, for each pet:
|  1. planned orders of that day: packs bought in the app (purchases,
|     auto subscription) and orders created from the back-office
|  2. old subscription calendar (animal meal dates) for the pets
|     without a planned order that day
|
| Address: the address chosen for the pack delivery replaces the
| subscription address IN MEMORY only (nothing is saved).
|
*/

class DailyReportPdfController extends Controller
{
    /** Planned orders with these statuses are not prepared. */
    private const SKIPPED_STATUSES = ['cancelled', 'canceled'];

    public function deliveryPdf(Subscription $subscription, Request $request)
    {
        $carbonDate = Carbon::parse((string) $request->input('date'))->startOfDay();
        $dateKey = $carbonDate->format('Y-m-d');

        $subscription->load('animals.mealDates.recipe');

        $plannedOrders = $this->plannedOrdersOfDay($dateKey, $subscription->id);

        $animalsForDay = $this->animalsForDay($subscription, $plannedOrders, $carbonDate, $dateKey);

        $this->applyPackAddress($subscription, $plannedOrders);

        $settings = Setting::instance();

        $html = view('daily-report.pdf-delivery', [
            'subscription'  => $subscription,
            'date'          => $dateKey,
            'animalsForDay' => $animalsForDay,
            'settings'      => $settings,
        ])->render();

        return $this->pdfResponse(
            $html,
            $settings,
            'delivery-' . $subscription->code . '-' . $dateKey . '.pdf'
        );
    }

    public function premiumPdf(Subscription $subscription, Request $request)
    {
        $carbonDate = Carbon::parse((string) $request->input('date'))->startOfDay();
        $dateKey = $carbonDate->format('Y-m-d');

        $subscription->load('animals.mealDates.recipe');

        $plannedOrders = $this->plannedOrdersOfDay($dateKey, $subscription->id);

        // Premium recipes only
        $animalsForDay = $this->animalsForDay($subscription, $plannedOrders, $carbonDate, $dateKey, true);

        $this->applyPackAddress($subscription, $plannedOrders);

        $settings = Setting::instance();

        $html = view('daily-report.pdf-premium', [
            'subscription'  => $subscription,
            'date'          => $dateKey,
            'animalsForDay' => $animalsForDay,
            'settings'      => $settings,
        ])->render();

        return $this->pdfResponse(
            $html,
            $settings,
            'premium-' . $subscription->code . '-' . $dateKey . '.pdf'
        );
    }

    public function dailyReportPdf(Request $request)
    {
        $carbonDate = Carbon::parse((string) $request->input('date'))->startOfDay();
        $dateKey = $carbonDate->format('Y-m-d');

        // All planned orders of the day, once (no query per customer)
        $plannedOrdersBySubscription = $this->plannedOrdersOfDay($dateKey)
            ->groupBy('subscription_id');

        $subscriptions = Subscription::with([
            'animals.mealDates.recipe',
        ])->get();

        /*
         * Customers with meals that day + their meals (pets)
         */
        $mealsBySubscription = [];

        $subscriptions = $subscriptions->filter(function ($subscription) use (
            $plannedOrdersBySubscription,
            $carbonDate,
            $dateKey,
            &$mealsBySubscription
        ) {
            $orders = $plannedOrdersBySubscription->get($subscription->id, collect());

            $animalsForDay = $this->animalsForDay($subscription, $orders, $carbonDate, $dateKey);

            if (empty($animalsForDay)) {
                return false;
            }

            $mealsBySubscription[$subscription->id] = $animalsForDay;

            $this->applyPackAddress($subscription, $orders);

            return true;
        })->values();

        /*
         * Recipes needed (quantity per recipe)
         */
        $recipeCounts = [];
        $recipes = [];

        foreach ($mealsBySubscription as $animalsForDay) {
            foreach ($animalsForDay as $block) {
                foreach ($block['meals'] as $meal) {
                    $recipe = $meal['recipe'];
                    $rid = (int) $recipe->id;

                    $recipes[$rid] = $recipe;
                    $recipeCounts[$rid] = ($recipeCounts[$rid] ?? 0) + (int) $meal['quantity'];
                }
            }
        }

        $usedRecipeIds = array_keys($recipes);

        /*
         * Ingredients of these recipes
         */
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

        /*
         * Raw quantity per ingredient, per recipe
         */
        $ingredientTotalsTmp = [];

        foreach ($recipeCounts as $rid => $mealQty) {
            if (empty($recipeIngredientMap[$rid])) continue;

            foreach ($recipeIngredientMap[$rid] as $iid => $data) {
                $need = $mealQty * (float) ($data['qty_per_recipe'] ?? 0);

                if (!isset($ingredientTotalsTmp[$rid])) $ingredientTotalsTmp[$rid] = [];
                if (!isset($ingredientTotalsTmp[$rid][$iid])) $ingredientTotalsTmp[$rid][$iid] = 0;

                $ingredientTotalsTmp[$rid][$iid] += $need;
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

        return $this->pdfResponse(
            $html,
            $settings,
            'daily-report-' . $dateKey . '.pdf'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Planned orders of a day (one customer, or all), with their recipes.
     */
    private function plannedOrdersOfDay(string $dateKey, ?int $subscriptionId = null): Collection
    {
        return PlannedOrder::query()
            ->with(['items.recipe', 'animal'])
            ->when($subscriptionId, fn ($query) => $query->where('subscription_id', $subscriptionId))
            ->whereDate('scheduled_for', $dateKey)
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', self::SKIPPED_STATUSES);
            })
            ->get();
    }

    /**
     * Pets and meals of the day:
     * [['animal' => Animal, 'meals' => [['recipe' => Recipe, 'quantity' => int], ...]], ...]
     */
    private function animalsForDay(
        Subscription $subscription,
        Collection $plannedOrders,
        Carbon $carbonDate,
        string $dateKey,
        bool $premiumOnly = false
    ): array {
        $animalsForDay = [];
        $petsWithOrder = [];

        /*
         * 1. Planned orders (purchases + back-office)
         */
        foreach ($plannedOrders->groupBy('animal_id') as $animalId => $orders) {
            $animal = $orders->first()->animal
                ?? $subscription->animals->firstWhere('id', (int) $animalId);

            if (!$animal) {
                continue;
            }

            // The pet has an order that day: its old calendar is not used
            $petsWithOrder[(int) $animal->id] = true;

            // Same recipe in 2 orders of the same day: one line
            $meals = [];

            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    $quantity = (int) ($item->quantity ?? 0);
                    $recipe = $item->recipe;

                    if (!$recipe || $quantity <= 0) {
                        continue;
                    }

                    if ($premiumOnly && !(bool) ($recipe->is_premium ?? false)) {
                        continue;
                    }

                    $rid = (int) $recipe->id;

                    if (!isset($meals[$rid])) {
                        $meals[$rid] = ['recipe' => $recipe, 'quantity' => 0];
                    }

                    $meals[$rid]['quantity'] += $quantity;
                }
            }

            if (!empty($meals)) {
                $animalsForDay[] = [
                    'animal' => $animal,
                    'meals'  => array_values($meals),
                ];
            }
        }

        /*
         * 2. Old subscription calendar (pets without a planned order)
         */
        foreach ($subscription->animals as $animal) {
            if (isset($petsWithOrder[(int) $animal->id])) {
                continue;
            }

            if (!$this->isActiveOn($animal, $carbonDate)) {
                continue;
            }

            $meals = [];

            foreach ($animal->mealDates as $mealDate) {
                $md = $mealDate->meal_date ? Carbon::parse($mealDate->meal_date)->format('Y-m-d') : null;
                $quantity = (int) ($mealDate->quantity ?? 0);
                $recipe = $mealDate->recipe;

                if ($md !== $dateKey || $quantity <= 0 || !$recipe) {
                    continue;
                }

                if ($premiumOnly && !(bool) ($recipe->is_premium ?? false)) {
                    continue;
                }

                $rid = (int) $recipe->id;

                if (!isset($meals[$rid])) {
                    $meals[$rid] = ['recipe' => $recipe, 'quantity' => 0];
                }

                $meals[$rid]['quantity'] += $quantity;
            }

            if (!empty($meals)) {
                $animalsForDay[] = [
                    'animal' => $animal,
                    'meals'  => array_values($meals),
                ];
            }
        }

        return $animalsForDay;
    }

    /**
     * Old subscription: pet active that day (start / end dates).
     */
    private function isActiveOn($animal, Carbon $carbonDate): bool
    {
        $start = $animal->subscription_start ? Carbon::parse($animal->subscription_start)->startOfDay() : null;
        $end   = $animal->subscription_end ? Carbon::parse($animal->subscription_end)->endOfDay() : null;

        if ($start && $end) return $carbonDate->between($start, $end);
        if ($start) return $carbonDate->gte($start);
        if ($end) return $carbonDate->lte($end);

        return true;
    }

    /**
     * Address + slot of the pack delivery (in memory only).
     */
    private function applyPackAddress(Subscription $subscription, Collection $plannedOrders): void
    {
        $orderIds = $plannedOrders->pluck('id')->filter()->all();

        if (empty($orderIds)) {
            return;
        }

        $packDelivery = PurchaseDelivery::query()
            ->with('customerAddress.zone.province')
            ->whereIn('planned_order_id', $orderIds)
            ->whereNotNull('customer_address_id')
            ->orderBy('id')
            ->first();

        $address = $packDelivery?->customerAddress;

        if (!$address) {
            return;
        }

        $values = $address->subscriberAttributes();

        if (filled($packDelivery->delivery_slot)) {
            $values['subscriber_delivery_slot'] = $packDelivery->delivery_slot;
        }

        foreach ($values as $key => $value) {
            $subscription->setAttribute($key, $value);
        }

        // Never written back by an accidental save()
        $subscription->syncOriginalAttributes(array_keys($values));
    }

    private function pdfResponse(string $html, $settings, string $fileName)
    {
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
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ]
        );
    }
}

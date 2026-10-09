<?php

namespace App\Http\Controllers;

use App\Models\AnimalMealDate;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    private const ORANGE_PERCENT = 10;
    private const FORECAST_MAX_DAYS = 365;

    public function index(Request $request)
    {
        $action = $request->query('action');
        $dateParam = $request->query('date');

        $selectedDate = $dateParam
            ? Carbon::parse($dateParam)->toDateString()
            : null;

        /*
        |--------------------------------------------------------------------------
        | EDIT MODE
        |--------------------------------------------------------------------------
        */
        if ($action === 'edit' && $dateParam) {

            $stockDate = Carbon::parse($dateParam)->toDateString();

            $ingredients = Ingredient::query()
                ->orderBy('name')
                ->get();

            $existing = IngredientStock::query()
                ->whereDate('stock_date', $stockDate)
                ->get()
                ->keyBy('ingredient_id');

            return view('stock.index', [
                'mode'            => 'edit',
                'selectedDate'    => $selectedDate,
                'stockDate'       => $stockDate,
                'ingredients'     => $ingredients,
                'existing'        => $existing,
                'latestStockDate' => null,
                'latestStocks'    => collect(),
                'statusRows'      => [],
                'endDate'         => null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | STATUS MODE
        |--------------------------------------------------------------------------
        */

        $endDate = $dateParam
            ? Carbon::parse($dateParam)->toDateString()
            : Carbon::today()->toDateString();

        $latestStockDate = IngredientStock::query()
            ->max('stock_date');

        $ingredients = Ingredient::query()
            ->orderBy('name')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | NO STOCK RECORDED
        |--------------------------------------------------------------------------
        */
        if (!$latestStockDate) {

            $statusRows = $ingredients->map(function ($ingredient) {
                return [
                    'ingredient'       => $ingredient,
                    'last_qty'         => 0,
                    'consumed'         => 0,
                    'remaining'        => 0,
                    'status'           => 'no_stock',
                    'exhaustion_date'  => null,
                    'exhaustion_label' => '-',
                    'end_date'         => null,
                ];
            })->toArray();

            return view('stock.index', [
                'mode'            => 'status',
                'selectedDate'    => $selectedDate,
                'stockDate'       => null,
                'ingredients'     => $ingredients,
                'existing'        => collect(),
                'latestStockDate' => null,
                'latestStocks'    => collect(),
                'statusRows'      => $statusRows,
                'endDate'         => $endDate,
            ]);
        }

        $latestStockDate = Carbon::parse($latestStockDate)
            ->toDateString();

        /*
        |--------------------------------------------------------------------------
        | GET LATEST STOCK
        |--------------------------------------------------------------------------
        */
        $latestStocks = IngredientStock::query()
            ->whereDate('stock_date', $latestStockDate)
            ->get()
            ->keyBy('ingredient_id');

        /*
        |--------------------------------------------------------------------------
        | FORECAST END
        |--------------------------------------------------------------------------
        |
        | Keep the same rule as your previous controller:
        | forecast at least from today + 365 days.
        |
        */

        $forecastEnd = Carbon::parse($endDate)->startOfDay();
        $today = Carbon::today();

        if ($forecastEnd->lt($today)) {
            $forecastEnd = $today->copy();
        }

        $forecastEnd = $forecastEnd
            ->addDays(self::FORECAST_MAX_DAYS)
            ->toDateString();

        /*
        |--------------------------------------------------------------------------
        | CALCULATE CONSUMPTION + EXHAUSTION IN ONE PASS
        |--------------------------------------------------------------------------
        */

        $calculation = $this->computeStockUsage(
            $latestStockDate,
            $endDate,
            $forecastEnd,
            $latestStocks
        );

        $consumption = $calculation['consumption'];
        $exhaustionDates = $calculation['exhaustion'];

        /*
        |--------------------------------------------------------------------------
        | BUILD STATUS ROWS
        |--------------------------------------------------------------------------
        */

        $statusRows = $ingredients->map(
            function ($ingredient) use (
                $latestStocks,
                $consumption,
                $exhaustionDates,
                $endDate
            ) {

                $lastQty = isset($latestStocks[$ingredient->id])
                    ? (float) $latestStocks[$ingredient->id]->quantity
                    : 0;

                $consumed = (float) (
                    $consumption[$ingredient->id] ?? 0
                );

                $remaining = $lastQty - $consumed;

                /*
                |--------------------------------------------------------------------------
                | STATUS
                |--------------------------------------------------------------------------
                */

                if ($lastQty <= 0 || $remaining <= 0) {

                    $status = 'stock_out';

                } else {

                    $orangeThreshold =
                        $lastQty * (self::ORANGE_PERCENT / 100);

                    $status = $remaining <= $orangeThreshold
                        ? 'almost'
                        : 'ok';
                }

                /*
                |--------------------------------------------------------------------------
                | EXHAUSTION
                |--------------------------------------------------------------------------
                */

                $exhaustionDate =
                    $exhaustionDates[$ingredient->id] ?? null;

                $exhaustionLabel = '-';

                if ($lastQty <= 0) {

                    $exhaustionDate = null;
                    $exhaustionLabel = 'No stock';

                } elseif (!$exhaustionDate) {

                    $exhaustionLabel =
                        'Not exhausted in forecast';
                }

                return [
                    'ingredient'       => $ingredient,
                    'last_qty'         => $lastQty,
                    'consumed'         => $consumed,
                    'remaining'        => $remaining,
                    'status'           => $status,
                    'exhaustion_date'  => $exhaustionDate,
                    'exhaustion_label' => $exhaustionLabel,
                    'end_date'         => $endDate,
                ];
            }
        )->toArray();

        return view('stock.index', [
            'mode'            => 'status',
            'selectedDate'    => $selectedDate,
            'stockDate'       => null,
            'ingredients'     => $ingredients,
            'existing'        => collect(),
            'latestStockDate' => $latestStockDate,
            'latestStocks'    => $latestStocks,
            'statusRows'      => $statusRows,
            'endDate'         => $endDate,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE STOCK
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        $request->validate([
            'stock_date'     => 'required|date',
            'quantities'     => 'required|array',
            'quantities.*'   => 'nullable|numeric|min:0',
        ]);

        $stockDate = Carbon::parse(
            $request->stock_date
        )->toDateString();

        $quantities = $request->input(
            'quantities',
            []
        );

        $ingredientIds = Ingredient::query()
            ->pluck('id');

        $now = now();

        $rows = [];

        foreach ($ingredientIds as $ingredientId) {

            $qty = $quantities[$ingredientId] ?? 0;

            $qty = is_numeric($qty)
                ? (float) $qty
                : 0;

            $rows[] = [
                'ingredient_id' => $ingredientId,
                'stock_date'    => $stockDate,
                'quantity'      => $qty,
                'created_by'    => Auth::id(),
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | UPSERT
        |--------------------------------------------------------------------------
        |
        | Much faster than updateOrCreate() inside a loop.
        |
        */

        DB::transaction(function () use ($rows) {

            IngredientStock::upsert(
                $rows,
                [
                    'ingredient_id',
                    'stock_date',
                ],
                [
                    'quantity',
                    'created_by',
                    'updated_at',
                ]
            );

        });

        return redirect()
            ->route('stock.index', [
                'action' => 'edit',
                'date'   => $stockDate,
            ])
            ->with(
                'success',
                'Stock saved for ' . $stockDate
            );
    }

    /*
    |--------------------------------------------------------------------------
    | OPTIMIZED STOCK CALCULATION
    |--------------------------------------------------------------------------
    |
    | Replaces:
    |
    | computeConsumptionBetweenDates()
    | computeDailyConsumption()
    | computeExhaustionDates()
    |
    | Meal dates are loaded ONLY for the required period.
    |
    */

    private function computeStockUsage(
        string $latestStockDate,
        string $endDate,
        string $forecastEndDate,
        $latestStocks
    ): array {

        $startDate = Carbon::parse($latestStockDate)
            ->addDay()
            ->toDateString();

        /*
        |--------------------------------------------------------------------------
        | INITIAL RESULT
        |--------------------------------------------------------------------------
        */

        $consumption = [];
        $exhaustion = [];
        $remaining = [];

        foreach ($latestStocks as $ingredientId => $stock) {
            $remaining[(int) $ingredientId] =
                (float) $stock->quantity;
        }

        /*
        |--------------------------------------------------------------------------
        | INVALID PERIOD
        |--------------------------------------------------------------------------
        */

        if (
            Carbon::parse($startDate)
                ->gt(Carbon::parse($forecastEndDate))
        ) {
            return [
                'consumption' => $consumption,
                'exhaustion'  => $exhaustion,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | LOAD ONLY REQUIRED MEAL DATES
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | We no longer load every subscription and every meal date.
        |
        */

        $mealDates = AnimalMealDate::query()

            ->whereBetween(
                'meal_date',
                [
                    $startDate,
                    $forecastEndDate,
                ]
            )

            /*
            |--------------------------------------------------------------------------
            | LOAD ANIMAL
            |--------------------------------------------------------------------------
            |
            | Required to verify subscription_start/subscription_end.
            |
            */

            ->with([
                'animal:id,subscription_start,subscription_end',

                /*
                |--------------------------------------------------------------------------
                | RECIPE + INGREDIENTS
                |--------------------------------------------------------------------------
                */

                'recipe:id',

                'recipe.ingredients:id,recipe_id,ingredient_id,quantity',

                'recipe.ingredients.ingredient:id,yield_percentage',
            ])

            ->orderBy('meal_date')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | PROCESS MEAL DATES ONCE
        |--------------------------------------------------------------------------
        */

        foreach ($mealDates as $mealDate) {

            if (!$mealDate->animal) {
                continue;
            }

            if (!$mealDate->recipe) {
                continue;
            }

            $mealDateCarbon = Carbon::parse(
                $mealDate->meal_date
            )->startOfDay();

            /*
            |--------------------------------------------------------------------------
            | CHECK ANIMAL SUBSCRIPTION PERIOD
            |--------------------------------------------------------------------------
            |
            | Same business rule as your previous controller.
            |
            */

            $animal = $mealDate->animal;

            $subscriptionStart =
                $animal->subscription_start
                    ? Carbon::parse(
                        $animal->subscription_start
                    )->startOfDay()
                    : null;

            $subscriptionEnd =
                $animal->subscription_end
                    ? Carbon::parse(
                        $animal->subscription_end
                    )->endOfDay()
                    : null;

            $isActive = true;

            if (
                $subscriptionStart &&
                $subscriptionEnd
            ) {

                $isActive = $mealDateCarbon->between(
                    $subscriptionStart,
                    $subscriptionEnd
                );

            } elseif (
                $subscriptionStart &&
                !$subscriptionEnd
            ) {

                $isActive = $mealDateCarbon
                    ->gte($subscriptionStart);

            } elseif (
                !$subscriptionStart &&
                $subscriptionEnd
            ) {

                $isActive = $mealDateCarbon
                    ->lte($subscriptionEnd);
            }

            if (!$isActive) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | PORTIONS
            |--------------------------------------------------------------------------
            */

            $portionCount = (int) $mealDate->quantity;

            if ($portionCount <= 0) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | RECIPE INGREDIENTS
            |--------------------------------------------------------------------------
            */

            foreach (
                $mealDate->recipe->ingredients
                as $recipeIngredient
            ) {

                $ingredient =
                    $recipeIngredient->ingredient;

                if (!$ingredient) {
                    continue;
                }

                $ingredientId =
                    (int) $ingredient->id;

                /*
                |--------------------------------------------------------------------------
                | FINAL QUANTITY
                |--------------------------------------------------------------------------
                */

                $finalNeeded =
                    (float) $recipeIngredient->quantity
                    * $portionCount;

                /*
                |--------------------------------------------------------------------------
                | YIELD
                |--------------------------------------------------------------------------
                */

                $yieldPercent =
                    $ingredient->yield_percentage;

                if (
                    $yieldPercent === null ||
                    (float) $yieldPercent <= 0
                ) {
                    $yieldPercent = 100;
                }

                $yieldFactor =
                    (float) $yieldPercent / 100;

                if ($yieldFactor <= 0) {
                    $yieldFactor = 1;
                }

                /*
                |--------------------------------------------------------------------------
                | RAW QUANTITY
                |--------------------------------------------------------------------------
                */

                $rawNeeded =
                    $finalNeeded / $yieldFactor;

                /*
                |--------------------------------------------------------------------------
                | CONSUMPTION UNTIL SELECTED END DATE
                |--------------------------------------------------------------------------
                */

                if (
                    $mealDateCarbon->lte(
                        Carbon::parse($endDate)
                    )
                ) {

                    if (
                        !isset(
                            $consumption[$ingredientId]
                        )
                    ) {
                        $consumption[$ingredientId] = 0;
                    }

                    $consumption[$ingredientId]
                        += $rawNeeded;
                }

                /*
                |--------------------------------------------------------------------------
                | EXHAUSTION FORECAST
                |--------------------------------------------------------------------------
                */

                if (
                    !array_key_exists(
                        $ingredientId,
                        $remaining
                    )
                ) {
                    $remaining[$ingredientId] = 0;
                }

                /*
                 * Once exhausted, we already know the first date.
                 */
                if (
                    isset(
                        $exhaustion[$ingredientId]
                    )
                ) {
                    continue;
                }

                $remaining[$ingredientId]
                    -= $rawNeeded;

                if (
                    $remaining[$ingredientId] <= 0
                ) {
                    $exhaustion[$ingredientId] =
                        $mealDateCarbon->toDateString();
                }
            }
        }

        return [
            'consumption' => $consumption,
            'exhaustion'  => $exhaustion,
        ];
    }
}
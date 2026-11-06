<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\CustomerAccount;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Automatic planning of a pack (Shopify orders: the customer does not
 * choose the recipes, the pack is planned for him).
 *
 *  - first delivery : next working day (J+1, after 18:00 J+2, no Sunday)
 *  - deliveries     : the MAX number of deliveries of the pack, recipes
 *                     shared equally (30 recipes, 1-3 deliveries
 *                     -> 10 + 10 + 10), trial pack = usually 1 delivery
 *  - rhythm         : the next delivery arrives when the previous meals are
 *                     eaten: 10 recipes in a 30 days pack -> +10 days
 *                     (Sunday -> Saturday before, so it arrives in time)
 *  - next pack      : $firstDate = day after the last meals of the
 *                     previous pack (several packs for the same pet)
 *
 * App purchases (planned automatically after the payment):
 *  - $template : distribution of a similar previous pack (same deliveries,
 *                same recipes) -> see templateFrom()
 *  - $staffRules: first delivery = the day after the order, even after 18:00
 *                (order 02/11 23:00 -> 03/11, order 03/11 00:30 -> 04/11)
 *
 * Saved with PurchaseScheduleService (same rules as the app), so the
 * customer (app) and the staff can still change it afterwards.
 */
class AutoDeliveryPlanner
{
    /** First delivery: order day + N days (SHOPIFY_FIRST_DELIVERY_DAYS). */
    public static function firstDeliveryAfterDays(): int
    {
        return max(1, (int) config('services.shopify.first_delivery_after_days', 1));
    }

    public function __construct(private PurchaseScheduleService $schedule)
    {
    }

    /**
     * $firstDate: first delivery wanted (next pack of the same pet), otherwise
     * order day + firstDeliveryAfterDays(). $slot: slot of every delivery
     * (null = slot of the address).
     *
     * @return array{ok: bool, message: string, next_start: ?Carbon}
     */
    public function plan(
        Purchase $purchase,
        CustomerAccount $customer,
        Animal $animal,
        Carbon $orderDate,
        ?int $addressId = null,
        string $changedByType = 'shopify',
        ?Carbon $firstDate = null,
        ?string $slot = null,
        ?array $template = null,
        bool $staffRules = false
    ): array {
        // PREMIUM-... untreated: nothing planned before the custom product
        if (\App\Support\PremiumPack::isPending($purchase)) {
            return [
                'ok' => false,
                'message' => 'PREMIUM pack: untreated. Contact the customer, assign his custom product (purchase page), then he plans his deliveries.',
                'next_start' => null,
            ];
        }

        $allowed = $this->schedule->allowedRecipes($purchase);

        if ($allowed->isEmpty()) {
            return ['ok' => false, 'message' => 'The pack has no recipe: deliveries not planned.', 'next_start' => null];
        }

        // Distribution of a similar previous pack (validated by templateFrom)
        $quantities = $template
            ? array_map(fn (array $row) => (int) array_sum($row), $template)
            : $this->splitQuantities($purchase);

        if (!$quantities) {
            return [
                'ok' => false,
                'message' => "Unable to split {$purchase->total_recipes} recipes with the pack rules "
                    . "({$purchase->min_deliveries}-{$purchase->max_deliveries} deliveries, "
                    . "{$purchase->min_recipes_per_delivery}-{$purchase->max_recipes_per_delivery} recipes/delivery).",
                'next_start' => null,
            ];
        }

        $recipeRows = $template && $this->recipesFit($allowed, $template)
            ? $template
            : $this->shareRecipes($allowed, $quantities);

        if (!$recipeRows) {
            return ['ok' => false, 'message' => 'Not enough recipes in the pack for the automatic planning (max per recipe).', 'next_start' => null];
        }

        // J+1 (or the date given for a next pack), never before the first
        // date allowed now (18:00 cutoff), never on Sunday
        $start = $firstDate
            ? $firstDate->copy()->startOfDay()
            : $orderDate->copy()->startOfDay()->addDays(self::firstDeliveryAfterDays());
        $earliest = $this->schedule->earliestDeliveryDate($staffRules);

        if ($start->lt($earliest)) {
            $start = $earliest->copy();
        }

        // Next pack on a Sunday: Saturday before, so the meals never run out
        if ($firstDate && in_array($start->dayOfWeek, PurchaseScheduleService::REST_DAYS, true)
            && $start->copy()->subDay()->gte($earliest)) {
            $start->subDay();
        }

        $lastError = '';

        // The pet may already have a delivery that day: next days are tried
        for ($attempt = 0; $attempt < 7; $attempt++) {
            $start = $this->skipRestDay($start);
            $dates = $this->dates($purchase, $start, $quantities);

            if (!$dates) {
                return ['ok' => false, 'message' => 'Unable to place the deliveries inside the pack period.', 'next_start' => null];
            }

            $rows = [];

            foreach ($dates as $index => $date) {
                $rows[] = [
                    'date' => $date->format('Y-m-d'),
                    'address_id' => $addressId,
                    // null = slot of the address
                    'delivery_slot' => $slot,
                    'items' => collect($recipeRows[$index])
                        ->map(fn ($quantity, $recipeId) => ['recipe_id' => $recipeId, 'quantity' => $quantity])
                        ->values()
                        ->all(),
                ];
            }

            try {
                $this->schedule->saveSchedule($purchase, $rows, $customer, $animal->id, $changedByType);

                $summary = collect($dates)
                    ->map(fn ($d, $i) => $d->format('D d/m') . ' (' . $quantities[$i] . ')')
                    ->implode(', ');

                // Day the meals of the last delivery are eaten = next pack
                $nextStart = end($dates)->copy()->addDays($this->daysFor($purchase, $start, end($quantities)));

                return [
                    'ok' => true,
                    'message' => count($dates) . " deliveries planned: {$summary}.",
                    'next_start' => $nextStart,
                ];
            } catch (ValidationException $exception) {
                $lastError = collect($exception->errors())->flatten()->first() ?: 'Planning refused.';

                if (!str_contains($lastError, 'already has a delivery')) {
                    break;
                }

                $start->addDay();
            }
        }

        return ['ok' => false, 'message' => 'Automatic planning failed: ' . $lastError, 'next_start' => null];
    }

    /*
    |--------------------------------------------------------------------------
    | SAME DISTRIBUTION AS A SIMILAR PREVIOUS PACK
    |--------------------------------------------------------------------------
    |
    | Previous pack of the same pet (then of the same customer) with the same
    | product, otherwise the same number of meals for this pet: its
    | deliveries become the model [[recipe_id => qty], ...].
    |  - recipes still in the pack     -> same recipes, same quantities
    |  - other recipes (other age...)  -> same number of deliveries and
    |                                     meals per delivery, recipes of
    |                                     the new pack shared (plan())
    | Refused when it does not fit the rules of the new pack.
    |
    */

    /** @return array<int, array<int, int>>|null */
    public function templateFrom(Purchase $purchase): ?array
    {
        $candidates = Purchase::query()
            ->where('id', '!=', $purchase->id)
            ->whereIn('status', ['paid', 'active', 'completed', 'expired'])
            ->where(function ($q) use ($purchase) {
                $q->where(fn ($same) => $same->where('product_code', $purchase->product_code)
                    ->where(fn ($owner) => $owner
                        ->where('animal_id', $purchase->animal_id)
                        ->orWhere('customer_account_id', $purchase->customer_account_id)
                        ->when($purchase->subscription_id, fn ($w) => $w->orWhere('subscription_id', $purchase->subscription_id))))
                    ->orWhere(fn ($pet) => $pet->where('animal_id', $purchase->animal_id)
                        ->where('total_recipes', $purchase->total_recipes));
            })
            ->orderByRaw('CASE WHEN product_code = ? AND animal_id = ? THEN 0 WHEN product_code = ? THEN 1 ELSE 2 END', [
                $purchase->product_code,
                (int) $purchase->animal_id,
                $purchase->product_code,
            ])
            ->orderByDesc('id')
            ->limit(6)
            ->with(['deliveries' => fn ($q) => $q->where('status', '!=', 'cancelled')->orderBy('delivery_date'), 'deliveries.items'])
            ->get();

        foreach ($candidates as $previous) {
            $rows = $previous->deliveries
                ->map(fn ($delivery) => $delivery->items
                    ->groupBy('recipe_id')
                    ->map(fn ($items) => (int) $items->sum('quantity'))
                    ->filter(fn ($qty) => $qty > 0)
                    ->all())
                ->filter()
                ->values()
                ->all();

            if ($rows && $this->quantitiesFit($purchase, $rows)) {
                return $rows;
            }
        }

        return null;
    }

    /** Number of deliveries / meals per delivery / total of the new pack. */
    private function quantitiesFit(Purchase $purchase, array $rows): bool
    {
        $count = count($rows);
        $sums = array_map(fn ($row) => (int) array_sum($row), $rows);

        return $count >= max(1, (int) $purchase->min_deliveries)
            && $count <= max(1, (int) $purchase->max_deliveries)
            && array_sum($sums) === (int) $purchase->total_recipes
            && min($sums) >= max(1, (int) $purchase->min_recipes_per_delivery)
            && max($sums) <= max(1, (int) $purchase->max_recipes_per_delivery);
    }

    /** Every recipe still in the pack, maximum of each recipe respected. */
    private function recipesFit($allowed, array $rows): bool
    {
        $used = [];

        foreach ($rows as $row) {
            foreach ($row as $recipeId => $qty) {
                if (!isset($allowed[$recipeId])) {
                    return false;
                }

                $used[$recipeId] = ($used[$recipeId] ?? 0) + $qty;
            }
        }

        foreach ($used as $recipeId => $qty) {
            $max = $allowed[$recipeId]['max_quantity'];

            if ($max !== null && $qty > $max) {
                return false;
            }
        }

        return true;
    }

    /**
     * Previous pack of the same pet in the same Shopify order (pack 2/5...):
     * this one starts when its last meals are eaten. null = no previous pack.
     */
    public function nextStartAfterPreviousPack(Purchase $purchase): ?Carbon
    {
        if (!$purchase->shopify_order_id || !$purchase->animal_id) {
            return null;
        }

        $previous = Purchase::where('shopify_order_id', $purchase->shopify_order_id)
            ->where('animal_id', $purchase->animal_id)
            ->where('id', '<', $purchase->id)
            ->orderByDesc('id')
            ->first();

        $last = $previous?->deliveries()
            ->where('status', '!=', 'cancelled')
            ->withSum('items as recipes_total', 'quantity')
            ->orderByDesc('delivery_date')
            ->first();

        if (!$last) {
            return null;
        }

        $date = Carbon::parse($last->delivery_date)->startOfDay();

        return $date->addDays($this->daysFor($previous, $date->copy(), (int) ($last->recipes_total ?: $previous->max_recipes_per_delivery)));
    }

    /**
     * Recipes per delivery: the maximum number of deliveries of the pack,
     * recipes shared equally (food stays fresh, next delivery before the
     * meals run out).
     * 30 recipes, 1-3 deliveries, 1-30/delivery -> [10, 10, 10]
     * 12 recipes, 2-4 deliveries, 3-6/delivery  -> [3, 3, 3, 3]
     *
     * @return int[]|null
     */
    public function splitQuantities(Purchase $purchase): ?array
    {
        $total = (int) $purchase->total_recipes;
        $minDeliveries = max(1, (int) $purchase->min_deliveries);
        $maxDeliveries = max($minDeliveries, (int) $purchase->max_deliveries);
        $minPer = max(1, (int) $purchase->min_recipes_per_delivery);
        $maxPer = max($minPer, (int) $purchase->max_recipes_per_delivery);

        if ($total <= 0) {
            return null;
        }

        // As many deliveries as the pack allows (fresh food, rhythm of the
        // meals), down to the minimum: 30 recipes, 1-3 deliveries -> 3 x 10
        foreach (range($maxDeliveries, $minDeliveries) as $count) {
            $low = intdiv($total, $count);
            $high = (int) ceil($total / $count);

            if ($low >= $minPer && $high <= $maxPer) {
                $remainder = $total - $low * $count;

                return array_map(
                    fn ($i) => $low + ($i < $remainder ? 1 : 0),
                    range(0, $count - 1)
                );
            }
        }

        return null;
    }

    /**
     * Recipes of each delivery: [[recipe_id => qty], ...]
     * Default recipes first, then the others, in turn, so every delivery
     * is varied. The maximum of a recipe in the pack is respected.
     */
    private function shareRecipes($allowed, array $quantities): ?array
    {
        $order = $allowed->sortByDesc(fn ($row) => $row['is_default'] ? 1 : 0)->keys()->values()->all();
        $used = array_fill_keys($order, 0);
        $pointer = 0;
        $rows = [];

        foreach ($quantities as $quantity) {
            $row = [];

            for ($n = 0; $n < $quantity; $n++) {
                $placed = false;

                for ($tries = 0; $tries < count($order); $tries++) {
                    $recipeId = $order[$pointer % count($order)];
                    $pointer++;

                    $max = $allowed[$recipeId]['max_quantity'];

                    if ($max === null || $used[$recipeId] < $max) {
                        $row[$recipeId] = ($row[$recipeId] ?? 0) + 1;
                        $used[$recipeId]++;
                        $placed = true;
                        break;
                    }
                }

                if (!$placed) {
                    return null;
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Days covered by $recipes meals in this pack
     * (30 recipes / 30 days pack: 10 recipes = 10 days).
     */
    private function daysFor(Purchase $purchase, Carbon $start, int $recipes): int
    {
        $periodDays = max(1, (int) $start->diffInDays($purchase->computeEndDate($start->copy())));
        $perRecipe = $periodDays / max(1, (int) $purchase->total_recipes);

        return max(1, (int) round($recipes * $perRecipe) - max(0, (int) config('services.shopify.delivery_lead_days', 0)));
    }

    /**
     * Dates: each delivery arrives when the meals of the previous one are
     * eaten (rhythm of the pack), never on Sunday (moved to Saturday before,
     * otherwise Monday), inside the pack period.
     *
     * @param int[] $quantities recipes of each delivery
     * @return Carbon[]|null
     */
    private function dates(Purchase $purchase, Carbon $start, array $quantities): ?array
    {
        $dates = [$start->copy()];
        $count = count($quantities);

        if ($count === 1) {
            return $dates;
        }

        $end = $purchase->computeEndDate($start->copy());
        $minGap = max(1, (int) ($purchase->min_days_between_deliveries ?: 1));

        for ($i = 1; $i < $count; $i++) {
            $previous = end($dates);
            $gap = max($minGap, $this->daysFor($purchase, $start, $quantities[$i - 1]));
            $date = $previous->copy()->addDays($gap);

            // Sunday: the day before if possible (arrives before the meals run out)
            if (in_array($date->dayOfWeek, PurchaseScheduleService::REST_DAYS, true)) {
                $before = $date->copy()->subDay();

                $date = $previous->diffInDays($before) >= $minGap && !in_array($before->dayOfWeek, PurchaseScheduleService::REST_DAYS, true)
                    ? $before
                    : $this->skipRestDay($date);
            }

            if ($date->gt($end)) {
                $date = $end->copy();

                while (in_array($date->dayOfWeek, PurchaseScheduleService::REST_DAYS, true) || $date->lte($previous)) {
                    $date->subDay();
                }
            }

            if ($date->lte($previous)) {
                return null;
            }

            $dates[] = $date;
        }

        return $dates;
    }

    private function skipRestDay(Carbon $date): Carbon
    {
        while (in_array($date->dayOfWeek, PurchaseScheduleService::REST_DAYS, true)) {
            $date->addDay();
        }

        return $date;
    }
}

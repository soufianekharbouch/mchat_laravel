<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\PlannedOrder;
use App\Models\PlannedOrderItem;
use App\Models\Purchase;
use App\Models\PurchaseDelivery;
use App\Models\Recipe;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Distribution of a purchased pack into deliveries.
 *
 * - checks the product rules copied on the purchase
 * - saves purchase_deliveries + purchase_delivery_items
 * - creates / updates the matching planned_orders, so the deliveries
 *   appear in the daily report exactly like subscription orders
 *
 * Used by the mobile app (customer). Can be reused by the back-office.
 */
class PurchaseScheduleService
{
    /**
     * A delivery can be changed until this hour, the day before.
     */
    public const CUTOFF_HOUR = 18;

    /**
     * Previous packs rules (plan the older pack first, start after it):
     * customer in the app only. Shopify / back-office plan in their own order.
     */
    private bool $customerRules = false;

    /**
     * planned_orders statuses that cannot be changed anymore.
     */
    public const LOCKED_PLANNED_STATUSES = ['prepared', 'shipped', 'delivered'];

    /*
    |--------------------------------------------------------------------------
    | DATES & EDITABILITY
    |--------------------------------------------------------------------------
    */

    /**
     * First date that can be chosen now.
     *  - customer (mobile app): before 18:00 -> tomorrow, after 18:00 -> the day after tomorrow
     *  - staff (back-office, auto subscription check): always tomorrow, even after 18:00
     */
    public function earliestDeliveryDate(bool $staff = false): Carbon
    {
        $now = now();
        $days = $staff || $now->hour < self::CUTOFF_HOUR ? 1 : 2;

        $date = Carbon::today()->addDays($days);

        // Sunday = rest day (no delivery), same rule as the back-office calendar
        if ($date->isSunday()) {
            $date->addDay();
        }

        return $date;
    }

    /**
     * Days without delivery (Carbon: 0 = Sunday).
     */
    public const REST_DAYS = [Carbon::SUNDAY];

    /**
     * Last moment to change a delivery: day before, 18:00.
     */
    public function cutoffFor(PurchaseDelivery $delivery): Carbon
    {
        return Carbon::parse($delivery->delivery_date)
            ->subDay()
            ->setTime(self::CUTOFF_HOUR, 0);
    }

    /**
     * Can this delivery still be changed?
     *  - customer: until the day before, 18:00
     *  - staff: until it is prepared (no 18:00 rule), never a past day
     */
    public function isEditable(PurchaseDelivery $delivery, bool $staff = false): bool
    {
        if ($delivery->status !== 'scheduled') {
            return false;
        }

        $plannedStatus = $delivery->plannedOrder?->status;

        if ($plannedStatus && in_array($plannedStatus, self::LOCKED_PLANNED_STATUSES, true)) {
            return false;
        }

        if ($staff) {
            return Carbon::parse($delivery->delivery_date)->startOfDay()->gte(Carbon::today());
        }

        return now()->lt($this->cutoffFor($delivery));
    }

    /**
     * Delivery status follows the daily report (planned order status).
     */
    public function syncDeliveryStatuses(Purchase $purchase): void
    {
        $purchase->loadMissing('deliveries.plannedOrder');

        foreach ($purchase->deliveries as $delivery) {
            if ($delivery->status === 'cancelled' || !$delivery->plannedOrder) {
                continue;
            }

            $status = match ($delivery->plannedOrder->status) {
                'prepared', 'shipped' => 'prepared',
                'delivered' => 'delivered',
                default => 'scheduled',
            };

            if ($status !== $delivery->status) {
                $delivery->update(['status' => $status]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ALLOWED RECIPES
    |--------------------------------------------------------------------------
    */

    /**
     * Recipes of the pack, keyed by id:
     * [id => ['recipe' => Recipe, 'max_quantity' => ?int, 'is_default' => bool]]
     */
    public function allowedRecipes(Purchase $purchase): Collection
    {
        if (!$purchase->product_id) {
            return collect();
        }

        return Recipe::query()
            ->join('product_recipes', 'product_recipes.recipe_id', '=', 'recipes.id')
            ->where('product_recipes.product_id', $purchase->product_id)
            ->orderBy('product_recipes.sort_order')
            ->select('recipes.*', 'product_recipes.max_quantity as pack_max_quantity', 'product_recipes.is_default as pack_is_default')
            ->get()
            ->mapWithKeys(fn ($recipe) => [
                $recipe->id => [
                    'recipe' => $recipe,
                    'max_quantity' => $recipe->pack_max_quantity !== null ? (int) $recipe->pack_max_quantity : null,
                    'is_default' => (bool) $recipe->pack_is_default,
                ],
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SEVERAL PACKS FOR THE SAME PET
    |--------------------------------------------------------------------------
    |
    | - an older pack of the pet still has meals to plan: it is planned first
    | - the deliveries of a new pack start when the meals of the previous
    |   packs are eaten (last delivery + days covered by its meals)
    |
    */

    /** Older paid pack of the same pet with meals not planned yet (null = none). */
    public function previousToPlan(Purchase $purchase): ?Purchase
    {
        if (!$purchase->animal_id) {
            return null;
        }

        return Purchase::query()
            ->where('animal_id', $purchase->animal_id)
            ->where('id', '<', $purchase->id)
            ->whereIn('status', ['paid', 'active'])
            // A premium pack waiting for our team cannot be planned: it does not block
            ->where(fn ($q) => $q->whereNull('premium_status')->orWhere('premium_status', '!=', 'pending'))
            ->orderBy('id')
            ->get()
            ->first(fn (Purchase $older) => $older->remainingRecipesCount() > 0);
    }

    /** First day for the new pack: when the meals of the previous packs are eaten. */
    public function startAfterPreviousPacks(Purchase $purchase): ?Carbon
    {
        if (!$purchase->animal_id) {
            return null;
        }

        $last = PurchaseDelivery::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_deliveries.purchase_id')
            ->where('purchases.animal_id', $purchase->animal_id)
            ->where('purchases.id', '!=', $purchase->id)
            ->whereIn('purchases.status', ['paid', 'active', 'completed'])
            ->where('purchase_deliveries.status', '!=', 'cancelled')
            ->orderByDesc('purchase_deliveries.delivery_date')
            ->select('purchase_deliveries.*')
            ->withSum('items as recipes_total', 'quantity')
            ->first();

        if (!$last) {
            return null;
        }

        /** @var Purchase $pack */
        $pack = Purchase::find($last->purchase_id);
        $date = Carbon::parse($last->delivery_date)->startOfDay();

        if (!$pack || $pack->total_recipes <= 0) {
            return $date->addDay();
        }

        // Days covered by the meals of the last delivery (rhythm of that pack)
        $periodDays = max(1, (int) $date->diffInDays($pack->computeEndDate($date->copy())));
        $days = max(1, (int) round(((int) $last->recipes_total ?: $pack->max_recipes_per_delivery) * $periodDays / $pack->total_recipes));

        $start = $date->copy()->addDays($days);

        while (in_array($start->dayOfWeek, self::REST_DAYS, true)) {
            $start->addDay();
        }

        return $start;
    }

    /** Earliest date for this pack (tomorrow rule + previous packs of the pet). */
    public function earliestDateFor(Purchase $purchase): Carbon
    {
        $earliest = $this->earliestDeliveryDate();
        $after = $this->startAfterPreviousPacks($purchase);

        return $after && $after->gt($earliest) ? $after : $earliest;
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE THE WHOLE DISTRIBUTION
    |--------------------------------------------------------------------------
    |
    | $rows = [
    |   ['id' => 12|null, 'date' => 'Y-m-d', 'delivery_slot' => '09:00-12:00',
    |    'address_id' => 5, 'items' => [['recipe_id' => 3, 'quantity' => 6], ...]],
    | ]
    |
    | - rows with an id update an editable delivery
    | - rows without id create a delivery
    | - editable deliveries missing from $rows are cancelled
    | - locked deliveries (prepared, delivered, past cutoff) are kept as they are
    |
    */

    public function saveSchedule(
        Purchase $purchase,
        array $rows,
        CustomerAccount $customer,
        ?int $animalId = null,
        string $changedByType = 'customer'
    ): Purchase {
        return DB::transaction(function () use ($purchase, $rows, $customer, $animalId, $changedByType) {
            /** @var Purchase $purchase */
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if (!in_array($purchase->status, ['paid', 'active'], true)) {
                $this->fail('status', 'Deliveries can only be planned once the purchase is paid.');
            }

            $this->customerRules = $changedByType === 'customer';

            // Older pack of the same pet with meals not planned: that one first
            if ($this->customerRules && ($previous = $this->previousToPlan($purchase))) {
                $this->fail('previous', "Plan the {$previous->remainingRecipesCount()} remaining meals of your previous pack first.");
            }

            // PREMIUM-... untreated: the team must assign the custom product first
            if (\App\Support\PremiumPack::isPending($purchase)) {
                $this->fail('status', \App\Support\PremiumPack::blockedMessage());
            }

            /*
             * Pet: required to create the daily report orders.
             */
            $animal = $this->resolveAnimal($purchase, $customer, $animalId);

            $purchase->load('deliveries.items', 'deliveries.plannedOrder');
            $this->syncDeliveryStatuses($purchase);
            $purchase->load('deliveries.items', 'deliveries.plannedOrder');

            $active = $purchase->deliveries->where('status', '!=', 'cancelled');
            // Staff (back-office / auto subscription): no 18:00 rule
            $staff = !$this->customerRules;
            $locked = $active->filter(fn ($d) => !$this->isEditable($d, $staff))->keyBy('id');
            $editable = $active->filter(fn ($d) => $this->isEditable($d, $staff))->keyBy('id');

            /*
             * Normalize the rows sent by the app.
             * A row pointing to a locked delivery is ignored (it cannot change).
             */
            $newRows = collect($rows)
                ->values()
                ->reject(fn ($row) => !empty($row['id']) && $locked->has((int) $row['id']))
                ->map(fn ($row, $index) => $this->normalizeRow($row, $index, $editable, $customer, $purchase))
                ->values();

            $allowed = $this->allowedRecipes($purchase);

            $this->validateDistribution($purchase, $animal, $locked, $newRows, $allowed);

            /*
             * Save.
             */
            $keptIds = $newRows->pluck('id')->filter()->all();

            // Editable deliveries removed by the customer
            foreach ($editable as $delivery) {
                if (!in_array($delivery->id, $keptIds, true)) {
                    $this->cancelDeliveryRecord($delivery);
                }
            }

            foreach ($newRows as $row) {
                $delivery = $row['id'] ? $editable->get($row['id']) : new PurchaseDelivery(['purchase_id' => $purchase->id]);

                $delivery->fill([
                    'delivery_number' => $delivery->delivery_number ?: 0,
                    'delivery_date' => $row['date'],
                    'delivery_slot' => $row['delivery_slot'],
                    'customer_address_id' => $row['address_id'],
                    'status' => 'scheduled',
                ]);
                $delivery->save();

                $delivery->items()->delete();

                foreach ($row['items'] as $recipeId => $quantity) {
                    $delivery->items()->create([
                        'recipe_id' => $recipeId,
                        'recipe_name' => $allowed[$recipeId]['recipe']->name,
                        'quantity' => $quantity,
                    ]);
                }

                $this->syncPlannedOrder($purchase, $delivery->fresh('items'), $animal);
            }

            $this->renumberDeliveries($purchase);

            /*
             * Pack period: starts with the first delivery when not set yet.
             */
            $firstDate = $purchase->deliveries()
                ->where('status', '!=', 'cancelled')
                ->min('delivery_date');

            if (!$purchase->starts_on && $firstDate) {
                $purchase->starts_on = Carbon::parse($firstDate);
                $purchase->ends_on = $purchase->computeEndDate($firstDate);
            }

            if (!$purchase->animal_id && $animal) {
                $purchase->animal_id = $animal->id;
                $purchase->animal_name = $animal->name;
            }

            $purchase->save();

            if ($purchase->status === 'paid' && $firstDate) {
                $purchase->changeStatus('active', 'Deliveries planned in the mobile app.', $changedByType);
            }

            return $purchase->fresh(['deliveries.items', 'deliveries.plannedOrder', 'deliveries.customerAddress']);
        });
    }

    /**
     * "Skip date": removes one delivery, its recipes go back to the remaining ones.
     */
    public function cancelDelivery(Purchase $purchase, PurchaseDelivery $delivery): void
    {
        DB::transaction(function () use ($purchase, $delivery) {
            $delivery->loadMissing('plannedOrder');

            if ((int) $delivery->purchase_id !== (int) $purchase->id) {
                $this->fail('delivery', 'Delivery not found.');
            }

            if (!$this->isEditable($delivery)) {
                $this->fail('delivery', 'This delivery can no longer be changed.');
            }

            $this->cancelDeliveryRecord($delivery);
            $this->renumberDeliveries($purchase);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    private function normalizeRow(array $row, int $index, Collection $editable, CustomerAccount $customer, Purchase $purchase): array
    {
        $label = 'Delivery ' . ($index + 1);

        $id = !empty($row['id']) ? (int) $row['id'] : null;

        if ($id && !$editable->has($id)) {
            $this->fail("deliveries.$index.id", "$label: unknown delivery.");
        }

        $date = $row['date'] ?? $row['delivery_date'] ?? null;

        try {
            $date = $date ? Carbon::createFromFormat('Y-m-d', (string) $date)->startOfDay() : null;
        } catch (\Throwable) {
            $date = null;
        }

        if (!$date) {
            $this->fail("deliveries.$index.date", "$label: invalid date.");
        }

        /*
         * Slot: sent value, else default address slot.
         */
        $slot = $row['delivery_slot'] ?? null;

        if ($slot !== null && !in_array($slot, CustomerAddress::DELIVERY_SLOTS, true)) {
            $this->fail("deliveries.$index.delivery_slot", "$label: invalid delivery time slot.");
        }

        /*
         * Address: must belong to the customer, default address otherwise.
         */
        $addressId = !empty($row['address_id']) ? (int) $row['address_id'] : null;

        if ($addressId) {
            $address = CustomerAddress::where('customer_account_id', $customer->id)->find($addressId);

            if (!$address) {
                $this->fail("deliveries.$index.address_id", "$label: address not found.");
            }
        } else {
            $address = CustomerAddress::where('customer_account_id', $customer->id)
                ->orderByDesc('is_default')
                ->first();
        }

        if (!$address) {
            $this->fail('address', 'Please add your delivery address first to plan your deliveries.');
        }

        $slot ??= $address?->delivery_slot;

        /*
         * Items: [recipe_id => quantity], quantities of the same recipe merged.
         */
        $items = [];

        foreach ((array) ($row['items'] ?? []) as $item) {
            $recipeId = (int) ($item['recipe_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($recipeId <= 0 || $quantity <= 0) {
                continue;
            }

            $items[$recipeId] = ($items[$recipeId] ?? 0) + $quantity;
        }

        if (empty($items)) {
            $this->fail("deliveries.$index.items", "$label: choose at least one recipe.");
        }

        return [
            'id' => $id,
            'index' => $index,
            'label' => $label,
            'date' => $date,
            'delivery_slot' => $slot,
            'address_id' => $address?->id,
            'items' => $items,
        ];
    }

    private function validateDistribution(
        Purchase $purchase,
        ?Animal $animal,
        Collection $locked,
        Collection $newRows,
        Collection $allowed
    ): void {
        // Customer: 18:00 rule + previous packs / staff: from tomorrow, any hour
        $earliest = $this->customerRules ? $this->earliestDateFor($purchase) : $this->earliestDeliveryDate(true);

        /*
         * All deliveries after save: locked ones + new rows.
         */
        $all = $locked->map(fn ($delivery) => [
            'id' => $delivery->id,
            'label' => 'Delivery #' . $delivery->delivery_number,
            'date' => Carbon::parse($delivery->delivery_date)->startOfDay(),
            'items' => $delivery->items->mapWithKeys(fn ($i) => [(int) $i->recipe_id => (int) $i->quantity])->all(),
            'locked' => true,
        ])->values()->concat($newRows->map(fn ($row) => $row + ['locked' => false]));

        if ($all->isEmpty()) {
            $this->fail('deliveries', 'Add at least one delivery.');
        }

        /*
         * Number of deliveries.
         */
        if ($all->count() > $purchase->max_deliveries) {
            $this->fail('deliveries', "This pack allows {$purchase->max_deliveries} deliveries maximum.");
        }

        /*
         * Dates: not too early, unique, inside the pack period.
         */
        foreach ($newRows as $row) {
            if (in_array($row['date']->dayOfWeek, self::REST_DAYS, true)) {
                $this->fail(
                    "deliveries.{$row['index']}.date",
                    "{$row['label']}: no delivery on Sunday (rest day)."
                );
            }

            if ($row['date']->lt($earliest)) {
                $this->fail(
                    "deliveries.{$row['index']}.date",
                    "{$row['label']}: the first available date is " . $earliest->format('Y-m-d') . '.'
                );
            }
        }

        $dates = $all->map(fn ($d) => $d['date']->format('Y-m-d'));

        if ($dates->count() !== $dates->unique()->count()) {
            $this->fail('deliveries', 'Two deliveries cannot be on the same day.');
        }

        $sortedDates = $all->pluck('date')->sort()->values();

        $start = $purchase->starts_on ? Carbon::parse($purchase->starts_on) : $sortedDates->first();
        $end = $purchase->ends_on ? Carbon::parse($purchase->ends_on) : $purchase->computeEndDate($start);

        foreach ($newRows as $row) {
            if ($row['date']->lt($start) || $row['date']->gt($end)) {
                $this->fail(
                    "deliveries.{$row['index']}.date",
                    "{$row['label']}: the date must be between " . $start->format('Y-m-d') . ' and ' . $end->format('Y-m-d') . '.'
                );
            }
        }

        if ($purchase->min_days_between_deliveries) {
            for ($i = 1; $i < $sortedDates->count(); $i++) {
                $gap = $sortedDates[$i - 1]->diffInDays($sortedDates[$i]);

                if ($gap < $purchase->min_days_between_deliveries) {
                    $this->fail(
                        'deliveries',
                        "Deliveries must be at least {$purchase->min_days_between_deliveries} day(s) apart."
                    );
                }
            }
        }

        /*
         * Recipes: allowed in the pack, quantity per delivery, totals.
         */
        $totalsByRecipe = [];

        foreach ($all as $delivery) {
            $count = array_sum($delivery['items']);

            if (!$delivery['locked']) {
                foreach (array_keys($delivery['items']) as $recipeId) {
                    if (!$allowed->has($recipeId)) {
                        $this->fail("deliveries.{$delivery['index']}.items", "{$delivery['label']}: this recipe is not part of the pack.");
                    }
                }

                if ($count < $purchase->min_recipes_per_delivery || $count > $purchase->max_recipes_per_delivery) {
                    $this->fail(
                        "deliveries.{$delivery['index']}.items",
                        "{$delivery['label']}: {$count} recipe(s). Each delivery must contain between "
                        . "{$purchase->min_recipes_per_delivery} and {$purchase->max_recipes_per_delivery} recipes."
                    );
                }
            }

            foreach ($delivery['items'] as $recipeId => $quantity) {
                $totalsByRecipe[$recipeId] = ($totalsByRecipe[$recipeId] ?? 0) + $quantity;
            }
        }

        $total = array_sum($totalsByRecipe);

        if ($total > $purchase->total_recipes) {
            $this->fail('deliveries', "Total {$total} recipes: this pack contains {$purchase->total_recipes} recipes.");
        }

        if ($total === $purchase->total_recipes && $all->count() < $purchase->min_deliveries) {
            $this->fail('deliveries', "This pack must be split into at least {$purchase->min_deliveries} deliveries.");
        }

        foreach ($totalsByRecipe as $recipeId => $quantity) {
            $max = $allowed[$recipeId]['max_quantity'] ?? null;

            if ($max !== null && $quantity > $max) {
                $name = $allowed[$recipeId]['recipe']->name;
                $this->fail('deliveries', "\"{$name}\": {$max} maximum in this pack ({$quantity} chosen).");
            }
        }

        /*
         * The pet must not already have another order that day
         * (subscription calendar or another pack).
         */
        if ($animal) {
            $ourPlannedOrderIds = $purchase->deliveries->pluck('planned_order_id')->filter()->all();

            foreach ($newRows as $row) {
                $conflict = PlannedOrder::query()
                    ->where('animal_id', $animal->id)
                    ->whereDate('scheduled_for', $row['date']->format('Y-m-d'))
                    ->whereNotIn('id', $ourPlannedOrderIds ?: [0])
                    ->exists();

                if ($conflict) {
                    $this->fail(
                        "deliveries.{$row['index']}.date",
                        "{$row['label']}: {$animal->name} already has a delivery on " . $row['date']->format('Y-m-d') . '.'
                    );
                }
            }
        }
    }

    private function resolveAnimal(Purchase $purchase, CustomerAccount $customer, ?int $animalId): ?Animal
    {
        if ($purchase->animal_id) {
            $animal = Animal::find($purchase->animal_id);

            if ($animal) {
                return $animal;
            }
        }

        if (!$animalId) {
            $this->fail('animal_id', 'Choose the pet for this pack.');
        }

        $animal = Animal::where('subscription_id', $customer->subscription_id)->find($animalId);

        if (!$animal) {
            $this->fail('animal_id', 'Pet not found.');
        }

        if (($animal->species ?? null) !== $purchase->species) {
            $this->fail('animal_id', "This pack is for a {$purchase->species}.");
        }

        return $animal;
    }

    /*
    |--------------------------------------------------------------------------
    | PERSISTENCE HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Creates / updates the daily report order of a delivery.
     */
    private function syncPlannedOrder(Purchase $purchase, PurchaseDelivery $delivery, Animal $animal): void
    {
        $order = $delivery->planned_order_id ? PlannedOrder::find($delivery->planned_order_id) : null;

        if ($order && in_array($order->status, self::LOCKED_PLANNED_STATUSES, true)) {
            return;
        }

        $attributes = [
            'subscription_id' => $purchase->subscription_id ?? $animal->subscription_id,
            'animal_id' => $animal->id,
            'scheduled_for' => Carbon::parse($delivery->delivery_date)->format('Y-m-d'),
            'status' => 'planned',
        ];

        if ($order) {
            $order->fill($attributes)->save();
        } else {
            $order = PlannedOrder::create($attributes);
        }

        $order->items()->delete();

        foreach ($delivery->items as $item) {
            PlannedOrderItem::create([
                'planned_order_id' => $order->id,
                'recipe_id' => $item->recipe_id,
                'quantity' => $item->quantity,
            ]);
        }

        if ((int) $delivery->planned_order_id !== (int) $order->id) {
            $delivery->update(['planned_order_id' => $order->id]);
        }
    }

    private function cancelDeliveryRecord(PurchaseDelivery $delivery): void
    {
        $order = $delivery->planned_order_id ? PlannedOrder::find($delivery->planned_order_id) : null;

        if ($order && !in_array($order->status, self::LOCKED_PLANNED_STATUSES, true)) {
            $order->items()->delete();
            $order->delete();
        }

        /*
         * The row is removed (its recipes go back to the remaining ones).
         * Keeping it would block the same date later (unique purchase + date).
         */
        $delivery->items()->delete();
        $delivery->delete();
    }

    /**
     * 1, 2, 3... by date for active deliveries (cancelled ones keep their number).
     */
    private function renumberDeliveries(Purchase $purchase): void
    {
        $number = 1;

        $purchase->deliveries()
            ->where('status', '!=', 'cancelled')
            ->orderBy('delivery_date')
            ->get()
            ->each(function (PurchaseDelivery $delivery) use (&$number) {
                if ((int) $delivery->delivery_number !== $number) {
                    $delivery->update(['delivery_number' => $number]);
                }
                $number++;
            });
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}

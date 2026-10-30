<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Recipe;
use App\Models\Animal;
use App\Models\AnimalMealDate;
use App\Models\Province;
use App\Models\Zone;
use App\Models\PlannedOrder;
use App\Models\PlannedOrderItem;
use App\Models\PurchaseDelivery;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Support\SubscriptionCode;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Throwable;

class SubscriptionController extends Controller
{
    /**
     * Planned order statuses that must never be modified or deleted
     * from the subscription form (history is preserved).
     */
    private const LOCKED_ORDER_STATUSES = ['prepared', 'shipped', 'delivered'];

    public function index()
    {
        /*
         * Latest subscription first (highest id = last created).
         * plannedOrders.* loaded in advance: the view reads them for
         * every row (otherwise 1 query per subscription).
         */
        $subscriptions = Subscription::with([
                'animals.mealDates.recipe',
                'plannedOrders.animal',
                'plannedOrders.items.recipe',
                // Premium = pet with a PREMIUM-... pack paid / running
                'premiumPurchases:id,subscription_id,animal_id,animal_name,product_code,premium_status,status,ends_on',
            ])
            ->orderByDesc('id')
            ->get();
        return view('subscriptions.index', compact('subscriptions'));
    }

    public function create()
    {
        $recipes = Recipe::orderBy('name')->get();
        $provinces = Province::with('zones')->orderBy('name')->get();
        $nextSubscriptionCode = $this->generateNextSubscriptionCode();
        $shopifyCustomers = $this->getShopifyCustomers();

        return view('subscriptions.create', compact(
            'recipes',
            'provinces',
            'nextSubscriptionCode',
            'shopifyCustomers'
        ));
    }

    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            array_merge(
                [
                    'code' => 'required|unique:subscriptions,code',
                    'subscriber_first_name' => 'required|string',
                    'subscriber_last_name' => 'nullable|string',
                    'subscriber_phone' => 'required|string|max:30',
                    'shopify_customer_id' => 'nullable|string|max:100',
                ],
                $this->deliveryAddressRules($request),
                $this->animalRules()
            ),
            $this->deliveryAddressMessages()
        );

        $validator->after(function ($validator) use ($request) {
            $this->validateDogBreedSizes($validator, $request);
        });

        $validator->validate();

        $zone = Zone::with('province')->findOrFail((int) $request->address_zone_id);

        $animalsInput = $request->input('animals', []);

        DB::transaction(function () use ($request, $animalsInput, $zone) {
            /*
             * Code computed inside the transaction (row lock):
             * the number shown in the form may have been taken
             * meanwhile by another admin or by the mobile app.
             */
            $subscriptionCode = $this->resolveSubscriptionCode($request->code, $zone);

            $subscription = Subscription::create([
                'code' => $subscriptionCode,
                'creation_date' => now(),
                'subscriber_first_name' => $request->subscriber_first_name,
                'subscriber_last_name' => $request->subscriber_last_name,

                /*
                 * Copy of the delivery address, kept in sync for
                 * reports and PDFs. The reference is customer_addresses.
                 */
                'subscriber_address' => trim((string) $request->address_line),
                'subscriber_province' => $zone->province?->name,
                'subscriber_zone' => $zone->name,
                'subscriber_note' => $request->address_note,
                'subscriber_delivery_slot' => $request->address_delivery_slot,

                'delivery_days' => [],
                'day_recipes' => [],
                'subscriber_phone' => $request->subscriber_phone,
                'shopify_customer_id' => $request->shopify_customer_id,
            ]);

            foreach ($animalsInput as $index => $animalData) {
                $qrCodeImagePath = null;
                $lastOrderQrCodeImagePath = null;
                $photoPath = null;
                $transitionEnabled = !empty($animalData['transition']);
                $lastOrderEnabled = !empty($animalData['last_order']);
                $species = $animalData['species'];
                $breedSizeCategory = $species === 'Dog' ? ($animalData['breed_size_category'] ?? null) : null;

                if ($request->hasFile("animals.$index.photo")) {
                    $photoPath = $request
                        ->file("animals.$index.photo")
                        ->store('pets', 'public');
                }

                if ($transitionEnabled && $request->hasFile("animals.$index.qr_code_image")) {
                    $qrCodeImagePath = $request->file("animals.$index.qr_code_image")->store('animal_qr_codes', 'public');
                }

                if ($lastOrderEnabled && $request->hasFile("animals.$index.last_order_qr_code_image")) {
                    $lastOrderQrCodeImagePath = $request->file("animals.$index.last_order_qr_code_image")->store('animal_qr_codes', 'public');
                }

                $animal = $subscription->animals()->create([
                    'name' => $animalData['name'],
                    'species' => $species,
                    'breed_size_category' => $breedSizeCategory,
                    'age' => $animalData['age'] ?? null,
                    'health_status' => $animalData['health_status'] ?? null,
                    'note' => $animalData['note'] ?? null,
                    'photo_path' => $photoPath,
                    'transition' => $transitionEnabled ? 1 : 0,
                    'qr_code_image' => $transitionEnabled ? $qrCodeImagePath : null,
                    'last_order' => $lastOrderEnabled ? 1 : 0,
                    'last_order_qr_code_image' => $lastOrderEnabled ? $lastOrderQrCodeImagePath : null,
                    'subscription_start' => null,
                    'subscription_end' => null,
                ]);

                $dayMeals = $animalData['day_meals'] ?? [];
                $this->persistMealsByDate($animal, $dayMeals);
                $this->syncPlannedOrders($subscription, $animal, $dayMeals);
            }

            $customerAccount = $this->ensureCustomerAccount($subscription);

            $this->saveDeliveryAddress($customerAccount, $subscription, $request, $zone);
        });

        return redirect()->route('subscriptions.index')->with('success', 'Subscription created successfully.');
    }

    public function edit(Subscription $subscription)
    {
        $subscription->load([
            'customerAccount.addresses',
            'animals.mealDates.recipe',
            'plannedOrders.items.recipe',
            'animals.plannedOrders.items.recipe',
            'premiumPurchases',
        ]);

        $recipes = Recipe::orderBy('name')->get();
        $provinces = Province::with('zones')->orderBy('name')->get();
        $shopifyCustomers = $this->getShopifyCustomers();
        return view('subscriptions.edit', compact(
            'subscription',
            'recipes',
            'provinces',
            'shopifyCustomers'
        ));
    }

    public function update(Request $request, Subscription $subscription)
    {
        /*
         * Keep accepting a legacy slot value already saved
         * on this subscription or on its delivery address.
         */
        $subscription->loadMissing('deliveryAddress');

        $legacyDeliverySlots = array_values(array_filter([
            $subscription->subscriber_delivery_slot,
            $subscription->deliveryAddress?->delivery_slot,
        ]));

        $validator = Validator::make(
            $request->all(),
            array_merge(
                [
                    'code' => 'required|unique:subscriptions,code,' . $subscription->id,
                    'subscriber_first_name' => 'required|string',
                    'subscriber_last_name' => 'nullable|string',
                    'subscriber_phone' => 'required|string|max:30',
                    'animals.*.id' => 'nullable|integer',
                ],
                $this->deliveryAddressRules($request, $legacyDeliverySlots),
                $this->animalRules()
            ),
            $this->deliveryAddressMessages()
        );

        $validator->after(function ($validator) use ($request) {
            $this->validateDogBreedSizes($validator, $request);
        });

        $validator->validate();

        $zone = Zone::with('province')->findOrFail((int) $request->address_zone_id);

        $animalsInput = $request->input('animals', []);

        DB::transaction(function () use ($request, $subscription, $animalsInput, $zone) {
            $subscription->load('animals');

            /*
            |--------------------------------------------------------------------------
            | EXISTING ANIMALS
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            | Do not delete/recreate all animals here.
            |
            | Keeping the same Animal ID preserves:
            | - photo_path uploaded from the customer mobile app
            | - planned_orders animal_id references
            | - all other relations that depend on animal_id
            |
            */

            $existingAnimals =
                $subscription->animals
                    ->keyBy('id');

            $processedAnimalIds = [];

            $subscription->update([
                'code' => $request->code,
                'subscriber_first_name' => $request->subscriber_first_name,
                'subscriber_last_name' => $request->subscriber_last_name,

                /*
                 * Copy of the delivery address, kept in sync for
                 * reports and PDFs. The reference is customer_addresses.
                 */
                'subscriber_address' => trim((string) $request->address_line),
                'subscriber_province' => $zone->province?->name,
                'subscriber_zone' => $zone->name,
                'subscriber_note' => $request->address_note,
                'subscriber_delivery_slot' => $request->address_delivery_slot,

                'delivery_days' => [],
                'day_recipes' => [],
                'subscriber_phone' => $request->subscriber_phone,
                'shopify_customer_id' => $request->shopify_customer_id,
            ]);

            foreach ($animalsInput as $index => $animalData) {
                $transitionEnabled =
                    !empty($animalData['transition']);

                $lastOrderEnabled =
                    !empty($animalData['last_order']);

                $submittedAnimalId =
                    isset($animalData['id'])
                        ? (int) $animalData['id']
                        : null;

                /*
                |--------------------------------------------------------------------------
                | FIND EXISTING ANIMAL
                |--------------------------------------------------------------------------
                */

                $animal = null;

                if (
                    $submittedAnimalId
                    &&
                    $existingAnimals->has($submittedAnimalId)
                ) {
                    $animal = $existingAnimals->get($submittedAnimalId);
                } elseif (
                    !$submittedAnimalId
                    &&
                    isset($subscription->animals[$index])
                ) {
                    $animal = $subscription->animals[$index];
                }

                /*
                |--------------------------------------------------------------------------
                | QR CODE IMAGES
                |--------------------------------------------------------------------------
                */

                $qrCodeImagePath = $animal?->qr_code_image;

                $lastOrderQrCodeImagePath = $animal?->last_order_qr_code_image;

                /*
                |--------------------------------------------------------------------------
                | CUSTOMER PET PHOTO
                |--------------------------------------------------------------------------
                |
                | Keep the customer-uploaded photo by default.
                | Replace it only when the admin explicitly uploads a new photo.
                |
                */
                $photoPath = $animal?->photo_path;

                if ($request->hasFile("animals.$index.photo")) {
                    if (
                        !empty($photoPath)
                        &&
                        Storage::disk('public')->exists($photoPath)
                    ) {
                        Storage::disk('public')->delete($photoPath);
                    }

                    $photoPath = $request
                        ->file("animals.$index.photo")
                        ->store(
                            'pets/' . ($animal ? $animal->id : 'admin'),
                            'public'
                        );
                }

                if ($transitionEnabled) {
                    if ($request->hasFile("animals.$index.qr_code_image")) {
                        if (
                            !empty($qrCodeImagePath)
                            &&
                            Storage::disk('public')->exists($qrCodeImagePath)
                        ) {
                            Storage::disk('public')->delete($qrCodeImagePath);
                        }

                        $qrCodeImagePath = $request
                            ->file("animals.$index.qr_code_image")
                            ->store('animal_qr_codes', 'public');
                    } else {
                        $existingPath = $animalData['existing_qr_code_image'] ?? null;

                        if (!empty($existingPath)) {
                            $qrCodeImagePath = $existingPath;
                        }
                    }
                } else {
                    if (!empty($qrCodeImagePath)) {
                        Storage::disk('public')->delete($qrCodeImagePath);
                    }

                    $qrCodeImagePath = null;
                }

                if ($lastOrderEnabled) {
                    if ($request->hasFile("animals.$index.last_order_qr_code_image")) {
                        if (
                            !empty($lastOrderQrCodeImagePath)
                            &&
                            Storage::disk('public')->exists($lastOrderQrCodeImagePath)
                        ) {
                            Storage::disk('public')->delete($lastOrderQrCodeImagePath);
                        }

                        $lastOrderQrCodeImagePath = $request
                            ->file("animals.$index.last_order_qr_code_image")
                            ->store('animal_qr_codes', 'public');
                    } else {
                        $existingPath = $animalData['existing_last_order_qr_code_image'] ?? null;

                        if (!empty($existingPath)) {
                            $lastOrderQrCodeImagePath = $existingPath;
                        }
                    }
                } else {
                    if (!empty($lastOrderQrCodeImagePath)) {
                        Storage::disk('public')->delete($lastOrderQrCodeImagePath);
                    }

                    $lastOrderQrCodeImagePath = null;
                }

                $species = $animalData['species'];

                $breedSizeCategory = $species === 'Dog'
                    ? ($animalData['breed_size_category'] ?? null)
                    : null;

                $animalValues = [
                    'name' => $animalData['name'],
                    'species' => $species,
                    'breed_size_category' => $breedSizeCategory,
                    'age' => $animalData['age'] ?? null,
                    'health_status' => $animalData['health_status'] ?? null,
                    'transition' => $transitionEnabled ? 1 : 0,
                    'note' => $animalData['note'] ?? null,
                    'photo_path' => $photoPath,
                    'qr_code_image' => $transitionEnabled ? $qrCodeImagePath : null,
                    'last_order' => $lastOrderEnabled ? 1 : 0,
                    'last_order_qr_code_image' => $lastOrderEnabled ? $lastOrderQrCodeImagePath : null,
                ];

                if ($animal) {
                    $animal->update($animalValues);
                } else {
                    $animal = $subscription
                        ->animals()
                        ->create(
                            array_merge(
                                $animalValues,
                                [
                                    'subscription_start' => null,
                                    'subscription_end' => null,
                                ]
                            )
                        );
                }

                $processedAnimalIds[] = (int) $animal->id;

                /*
                |--------------------------------------------------------------------------
                | MEALS
                |--------------------------------------------------------------------------
                |
                | The calendar sent by the form is the full source of truth:
                | - animal_meal_dates is rebuilt from it
                | - planned_orders of days cleared in the calendar are removed
                |   (except prepared / shipped / delivered orders and
                |   orders created by a purchased pack)
                |
                */

                $animal->mealDates()->delete();

                $dayMeals = $animalData['day_meals'] ?? [];

                $this->persistMealsByDate($animal, $dayMeals);

                $this->syncPlannedOrders($subscription, $animal, $dayMeals);
            }

            /*
            |--------------------------------------------------------------------------
            | REMOVE ANIMALS DELETED FROM ADMIN FORM
            |--------------------------------------------------------------------------
            */

            $animalsToDelete = $existingAnimals->filter(
                function (Animal $animal) use ($processedAnimalIds) {
                    return !in_array((int) $animal->id, $processedAnimalIds, true);
                }
            );

            foreach ($animalsToDelete as $animalToDelete) {
                if (!empty($animalToDelete->photo_path)) {
                    Storage::disk('public')->delete($animalToDelete->photo_path);
                }

                if (!empty($animalToDelete->qr_code_image)) {
                    Storage::disk('public')->delete($animalToDelete->qr_code_image);
                }

                if (!empty($animalToDelete->last_order_qr_code_image)) {
                    Storage::disk('public')->delete($animalToDelete->last_order_qr_code_image);
                }

                $animalToDelete->mealDates()->delete();

                /*
                 * Remove the planned orders that are not yet prepared /
                 * shipped / delivered (and not created by a pack),
                 * so they disappear from the daily report.
                 */
                $this->deleteUnlockedPlannedOrders($animalToDelete);

                /*
                 * Do not silently destroy an animal that still has
                 * locked or pack planned orders. In that case keep it.
                 */
                if (!$animalToDelete->plannedOrders()->exists()) {
                    $animalToDelete->delete();
                }
            }

            $customerAccount = $this->ensureCustomerAccount($subscription);

            $this->saveDeliveryAddress($customerAccount, $subscription, $request, $zone);
        });

        return redirect()->route('subscriptions.index')->with('success', 'Subscription updated successfully.');
    }

    public function destroy(Subscription $subscription)
    {
        DB::transaction(function () use ($subscription) {
            $subscription->load('animals');

            foreach ($subscription->animals as $animal) {
                if (!empty($animal->qr_code_image)) {
                    Storage::disk('public')->delete($animal->qr_code_image);
                }

                if (!empty($animal->last_order_qr_code_image)) {
                    Storage::disk('public')->delete($animal->last_order_qr_code_image);
                }

                $animal->mealDates()->delete();
            }

            $subscription->animals()->delete();
            $subscription->plannedOrders()->delete();
            $subscription->delete();
        });

        return redirect()->route('subscriptions.index')->with('success', 'Subscription deleted successfully.');
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDATION HELPERS
    |--------------------------------------------------------------------------
    */

    private function deliveryAddressRules(Request $request, array $legacyDeliverySlots = []): array
    {
        $zoneExists = Rule::exists('zones', 'id');

        if ($request->filled('address_province_id')) {
            $zoneExists->where('province_id', (int) $request->input('address_province_id'));
        }

        $allowedDeliverySlots = array_values(array_unique(array_filter(array_merge(
            CustomerAddress::DELIVERY_SLOTS,
            $legacyDeliverySlots
        ))));

        return [
            'address_label' => 'nullable|string|max:100',
            'address_province_id' => 'required|integer|exists:provinces,id',
            'address_zone_id' => ['required', 'integer', $zoneExists],
            'address_line' => 'required|string|max:500',
            'address_note' => 'nullable|string|max:1000',
            'address_delivery_slot' => ['nullable', Rule::in($allowedDeliverySlots)],
        ];
    }

    private function deliveryAddressMessages(): array
    {
        return [
            'address_province_id.required' => 'Please select a province.',
            'address_zone_id.required' => 'Please select a zone.',
            'address_zone_id.exists' => 'The selected zone does not belong to the selected province.',
            'address_line.required' => 'Please enter the address details.',
            'subscriber_phone.required' => 'Phone number is required to create the customer account and address.',
        ];
    }

    private function animalRules(): array
    {
        return [
            'animals' => 'required|array|min:1',
            'animals.*.name' => 'required|string',
            'animals.*.species' => 'required|in:Cat,Dog',
            'animals.*.breed_size_category' => 'nullable|in:Small,Medium,Large',
            'animals.*.age' => 'nullable|string',
            'animals.*.health_status' => 'nullable|string',
            'animals.*.note' => 'nullable|string',
            'animals.*.photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'animals.*.transition' => 'nullable|boolean',
            'animals.*.qr_code_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'animals.*.last_order' => 'nullable|boolean',
            'animals.*.last_order_qr_code_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'animals.*.day_meals' => 'nullable|array',
        ];
    }

    private function validateDogBreedSizes($validator, Request $request): void
    {
        $animals = $request->input('animals', []);

        foreach ($animals as $index => $animal) {
            $species = $animal['species'] ?? null;
            $breedSizeCategory = $animal['breed_size_category'] ?? null;

            if ($species === 'Dog' && empty($breedSizeCategory)) {
                $validator->errors()->add(
                    "animals.$index.breed_size_category",
                    'The breed size category field is required when species is Dog.'
                );
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | DELIVERY ADDRESS (customer_addresses)
    |--------------------------------------------------------------------------
    |
    | Creates or updates the customer's DEFAULT address.
    | Other addresses saved by the customer in the app are not touched
    | (only their is_default flag is cleared).
    |
    */

    private function saveDeliveryAddress(
        CustomerAccount $customerAccount,
        Subscription $subscription,
        Request $request,
        Zone $zone
    ): CustomerAddress {
        $address = CustomerAddress::query()
            ->where('customer_account_id', $customerAccount->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if (!$address) {
            $address = new CustomerAddress([
                'customer_account_id' => $customerAccount->id,
            ]);
        }

        $label = trim((string) $request->input('address_label', ''));

        $address->fill([
            'label' => $label !== '' ? $label : ($address->label ?: 'Home'),
            'first_name' => $subscription->subscriber_first_name,
            'last_name' => $subscription->subscriber_last_name,
            'phone' => $subscription->subscriber_phone,
            'address' => trim((string) $request->address_line),
            'note' => $request->address_note,
            'delivery_slot' => $request->address_delivery_slot,
            'province_id' => $zone->province_id,
            'zone_id' => $zone->id,
            'city' => $zone->name,
            'province' => $zone->province?->name,
            'country' => $address->country ?: 'Kuwait',
            'is_default' => true,
        ]);

        $address->save();

        CustomerAddress::query()
            ->where('customer_account_id', $customerAccount->id)
            ->where('id', '!=', $address->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        return $address;
    }


    private function persistMealsByDate(Animal $animal, $dayMeals)
    {
        if (!is_array($dayMeals)) return;

        foreach ($dayMeals as $dateKey => $recipes) {
            $date = $this->normalizeDateKey($dateKey);
            if (!$date) continue;
            if (!is_array($recipes)) continue;

            foreach ($recipes as $recipeId => $qty) {
                $qtyInt = (int) $qty;
                if ($qtyInt <= 0) continue;

                AnimalMealDate::updateOrCreate(
                    [
                        'animal_id' => $animal->id,
                        'recipe_id' => (int) $recipeId,
                        'meal_date' => $date,
                    ],
                    [
                        'quantity' => $qtyInt,
                    ]
                );
            }
        }
    }

    /**
     * Synchronize planned_orders with the calendar submitted by the form.
     *
     * - Dates with meals: order created / items replaced
     * - Dates cleared or removed from the calendar: order deleted
     * - prepared / shipped / delivered orders are never touched
     * - orders created by a purchased pack are never touched
     */
    private function syncPlannedOrders(Subscription $subscription, Animal $animal, $dayMeals)
    {
        if (!is_array($dayMeals)) {
            $dayMeals = [];
        }

        $lockedStatuses = self::LOCKED_ORDER_STATUSES;

        /*
         * Dates that still have at least one meal in the calendar.
         */
        $keptDates = [];

        foreach ($dayMeals as $dateKey => $recipes) {
            $date = $this->normalizeDateKey($dateKey);
            if (!$date) continue;
            if (!is_array($recipes)) continue;

            $items = [];
            foreach ($recipes as $recipeId => $qty) {
                $q = (int) $qty;
                if ($q <= 0) continue;
                $items[(int) $recipeId] = $q;
            }

            /*
             * Orders created by a purchased pack (mobile app) are managed
             * by the pack itself: the subscription calendar never touches them.
             */
            $packOrder = PlannedOrder::where('animal_id', $animal->id)->whereDate('scheduled_for', $date)->first();
            if ($packOrder && PurchaseDelivery::where('planned_order_id', $packOrder->id)->exists()) {
                continue;
            }

            if (!count($items)) {
                // Empty day: it will be removed by the cleanup below.
                continue;
            }

            $keptDates[] = $date;

            $order = PlannedOrder::firstOrCreate(
                [
                    'animal_id' => $animal->id,
                    'scheduled_for' => $date,
                ],
                [
                    'subscription_id' => $subscription->id,
                    'status' => 'planned',
                ]
            );

            if ($order->subscription_id != $subscription->id) {
                $order->subscription_id = $subscription->id;
                $order->save();
            }

            if (in_array($order->status, $lockedStatuses, true)) {
                continue;
            }

            $order->items()->delete();

            foreach ($items as $recipeId => $qty) {
                PlannedOrderItem::create([
                    'planned_order_id' => $order->id,
                    'recipe_id' => $recipeId,
                    'quantity' => $qty,
                ]);
            }

            if ($order->status === 'overdue' && Carbon::parse($date)->startOfDay()->gte(Carbon::today())) {
                $order->status = 'planned';
                $order->save();
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CLEANUP: days cleared in the calendar
        |--------------------------------------------------------------------------
        |
        | Any non-locked, non-pack planned order of this animal whose date
        | is no longer in the calendar is deleted (with its items), so it
        | no longer appears in the daily report.
        |
        */
        $this->deleteUnlockedPlannedOrders($animal, $keptDates);
    }

    /**
     * Delete the planned orders (and their items) of an animal that are
     * not prepared / shipped / delivered and not created by a purchased pack.
     *
     * When $keepDates is given, orders scheduled on those dates are kept.
     */
    private function deleteUnlockedPlannedOrders(Animal $animal, array $keepDates = []): void
    {
        $lockedStatuses = self::LOCKED_ORDER_STATUSES;

        $orders = PlannedOrder::query()
            ->where('animal_id', $animal->id)
            ->where(function ($query) use ($lockedStatuses) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', $lockedStatuses);
            })
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        /*
         * Orders linked to a purchased pack (mobile app) are managed
         * by the pack itself: never delete them from here.
         */
        $packOrderIds = PurchaseDelivery::query()
            ->whereIn('planned_order_id', $orders->pluck('id'))
            ->pluck('planned_order_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($orders as $order) {
            if (in_array((int) $order->id, $packOrderIds, true)) {
                continue;
            }

            $orderDate = $order->scheduled_for
                ? Carbon::parse($order->scheduled_for)->format('Y-m-d')
                : null;

            if ($orderDate && in_array($orderDate, $keepDates, true)) {
                continue;
            }

            $order->items()->delete();
            $order->delete();
        }
    }

    private function normalizeDateKey($dateKey): ?string
    {
        if ($dateKey === null) return null;

        $s = trim((string) $dateKey);
        if ($s === '') return null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

        try {
            return Carbon::parse($s)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ensure that every subscription has a customer dashboard account.
     *
     * New accounts receive:
     * - a random 6-digit initial password
     * - a secure password hash for authentication
     * - an encrypted copy of the initial password for admin display
     * - password_changed_at = null until the customer chooses their own password
     */
    private function ensureCustomerAccount(
        Subscription $subscription
    ): CustomerAccount {
        $subscription->loadMissing('customerAccount');

        $normalizedPhone = $this->normalizeCustomerPhone(
            $subscription->subscriber_phone
        );

        if ($normalizedPhone === '') {
            throw ValidationException::withMessages([
                'subscriber_phone' =>
                    'Phone number is required to create customer account.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Existing customer account
        |--------------------------------------------------------------------------
        */
        if ($subscription->customerAccount) {
            $customerAccount = $subscription->customerAccount;

            $phoneUsedByAnotherAccount =
                CustomerAccount::query()
                    ->where('phone', $normalizedPhone)
                    ->where('id', '<>', $customerAccount->id)
                    ->exists();

            if ($phoneUsedByAnotherAccount) {
                throw ValidationException::withMessages([
                    'subscriber_phone' =>
                        'This phone number is already linked to another customer account.',
                ]);
            }

            if ((string) $customerAccount->phone !== $normalizedPhone) {
                $customerAccount->phone = $normalizedPhone;
                $customerAccount->save();
            }

            return $customerAccount;
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate phone
        |--------------------------------------------------------------------------
        */

        if (
            CustomerAccount::query()
                ->where('phone', $normalizedPhone)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'subscriber_phone' =>
                    'This phone number is already linked to another customer account.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Create customer dashboard account automatically
        |--------------------------------------------------------------------------
        */

        $initialPassword = $this->generateInitialCustomerPassword();

        return CustomerAccount::create([
            'subscription_id' => $subscription->id,
            'phone' => $normalizedPhone,
            'password' => $initialPassword,
            'initial_password_encrypted' => Crypt::encryptString($initialPassword),
            'password_changed_at' => null,
        ]);
    }


    /**
     * Reset / initialize the customer dashboard account.
     *
     * This intentionally invalidates the customer's current password
     * and creates a new random 6-digit initial password.
     */
    public function resetCustomerAccount(
        Subscription $subscription
    ): JsonResponse {
        try {
            return DB::transaction(
                function () use ($subscription) {
                    $subscription->loadMissing('customerAccount');

                    $normalizedPhone = $this->normalizeCustomerPhone(
                        $subscription->subscriber_phone
                    );

                    if ($normalizedPhone === '') {
                        return response()->json([
                            'success' => false,
                            'message' => 'Phone number is required to initialize the customer account.',
                        ], 422);
                    }

                    $initialPassword = $this->generateInitialCustomerPassword();

                    $customerAccount = $subscription->customerAccount;

                    /*
                    |--------------------------------------------------------------------------
                    | Create missing account
                    |--------------------------------------------------------------------------
                    */

                    if (!$customerAccount) {
                        $phoneUsedByAnotherAccount = CustomerAccount::query()
                            ->where('phone', $normalizedPhone)
                            ->exists();

                        if ($phoneUsedByAnotherAccount) {
                            return response()->json([
                                'success' => false,
                                'message' => 'This phone number is already linked to another customer account.',
                            ], 422);
                        }

                        $customerAccount = CustomerAccount::create([
                            'subscription_id' => $subscription->id,
                            'phone' => $normalizedPhone,
                            'password' => $initialPassword,
                            'initial_password_encrypted' => Crypt::encryptString($initialPassword),
                            'password_changed_at' => null,
                            'access_token_hash' => null,
                            'access_token_expires_at' => null,
                        ]);

                        /*
                         * New account: create its default address from
                         * the subscription address (if it can be matched).
                         */
                        $this->createDefaultAddressFromSubscription($customerAccount, $subscription);
                    } else {
                        /*
                        |--------------------------------------------------------------------------
                        | Existing account: reset credentials
                        |--------------------------------------------------------------------------
                        */

                        $phoneUsedByAnotherAccount = CustomerAccount::query()
                            ->where('phone', $normalizedPhone)
                            ->where('id', '<>', $customerAccount->id)
                            ->exists();

                        if ($phoneUsedByAnotherAccount) {
                            return response()->json([
                                'success' => false,
                                'message' => 'This phone number is already linked to another customer account.',
                            ], 422);
                        }

                        $customerAccount->phone = $normalizedPhone;
                        $customerAccount->password = $initialPassword;
                        $customerAccount->initial_password_encrypted = Crypt::encryptString($initialPassword);
                        $customerAccount->password_changed_at = null;

                        /*
                         * Force login again on all devices after reset.
                         */
                        $customerAccount->access_token_hash = null;
                        $customerAccount->access_token_expires_at = null;

                        $customerAccount->save();
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Customer account initialized successfully.',
                        'customer_account_id' => $customerAccount->id,
                        'initial_password' => $initialPassword,
                    ]);
                }
            );

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to initialize the customer account.',
            ], 500);
        }
    }


    /**
     * Creates the default customer address from the subscription text
     * columns, only when the account has no address yet.
     */
    private function createDefaultAddressFromSubscription(
        CustomerAccount $customerAccount,
        Subscription $subscription
    ): void {
        if (
            empty(trim((string) $subscription->subscriber_address))
            || $customerAccount->addresses()->exists()
        ) {
            return;
        }

        $province = Province::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim((string) $subscription->subscriber_province))])
            ->first();

        $zone = $province
            ? Zone::query()
                ->where('province_id', $province->id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim((string) $subscription->subscriber_zone))])
                ->first()
            : null;

        CustomerAddress::create([
            'customer_account_id' => $customerAccount->id,
            'label' => 'Home',
            'first_name' => $subscription->subscriber_first_name,
            'last_name' => $subscription->subscriber_last_name,
            'phone' => $subscription->subscriber_phone,
            'address' => trim((string) $subscription->subscriber_address),
            'note' => $subscription->subscriber_note,
            'delivery_slot' => $subscription->subscriber_delivery_slot,
            'province_id' => $province?->id,
            'zone_id' => $zone?->id,
            'city' => $zone?->name ?? $subscription->subscriber_zone,
            'province' => $province?->name ?? $subscription->subscriber_province,
            'country' => 'Kuwait',
            'is_default' => true,
        ]);
    }


    /**
     * Generate a cryptographically secure 6-digit password.
     */
    private function generateInitialCustomerPassword(): string
    {
        return (string) random_int(100000, 999999);
    }


    private function normalizeCustomerPhone(?string $phone): string
    {
        return preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
    }

    /**
     * Next back-office code: C + province (2 digits) + sequence (5 digits).
     * Same sequence as the app codes (C0300565-APP), see App\Support\SubscriptionCode.
     */
    private function generateNextSubscriptionCode(?string $provinceName = null): string
    {
        return SubscriptionCode::next($provinceName);
    }

    /**
     * Code to save on creation.
     *
     * - empty field: new code for the province of the zone
     * - automatic format (C0300564) whose number is already used,
     *   by any province or by the app: new number, same province
     * - any other code typed by hand: kept as is
     */
    private function resolveSubscriptionCode(?string $requestedCode, Zone $zone): string
    {
        $requestedCode = trim((string) $requestedCode);

        if ($requestedCode === '') {
            return SubscriptionCode::next($zone->province);
        }

        if (preg_match('/^C[0-9]{7}$/', $requestedCode)) {
            $sequence = substr($requestedCode, 3, 5);

            $taken = Subscription::query()
                ->where('code', 'REGEXP', '^C[0-9]{2}' . $sequence . '(-APP)?$')
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                return SubscriptionCode::next($zone->province);
            }
        }

        return $requestedCode;
    }

    private function getShopifyCustomers(): array
    {
        $storeDomain = config('services.shopify.store_domain');
        $accessToken = config('services.shopify.admin_access_token');
        $apiVersion = config('services.shopify.api_version', '2026-07');

        if (!$storeDomain || !$accessToken) {
            return [];
        }

        $query = <<<'GRAPHQL'
        query GetCustomers {
            customers(first: 50, sortKey: CREATED_AT, reverse: true) {
                edges {
                    node {
                        id
                        firstName
                        lastName
                        email
                    }
                }
            }
        }
        GRAPHQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->post("https://{$storeDomain}/admin/api/{$apiVersion}/graphql.json", [
            'query' => $query,
        ]);

        if (!$response->successful()) {
            return [];
        }

        return collect($response->json('data.customers.edges', []))
            ->map(function ($edge) {
                $customer = $edge['node'];

                $numericId = str_replace('gid://shopify/Customer/', '', $customer['id']);

                $name = trim(($customer['firstName'] ?? '') . ' ' . ($customer['lastName'] ?? ''));

                if ($name === '') {
                    $name = $customer['email'] ?? 'Unknown customer';
                }

                return [
                    'id' => $numericId,
                    'label' => $name . ' (' . $numericId . ')',
                ];
            })
            ->values()
            ->toArray();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Recipe;
use App\Models\Animal;
use App\Models\AnimalMealDate;
use App\Models\Province;
use App\Models\PlannedOrder;
use App\Models\PlannedOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use App\Models\CustomerAccount;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
class SubscriptionController extends Controller
{
    public function index()
    {
        $subscriptions = Subscription::with('animals.mealDates.recipe')->get();
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
        $subscriptionCode = $request->code ?: $this->generateNextSubscriptionCode($request->subscriber_province);
        $validator = Validator::make($request->all(), [
            'code' => 'required|unique:subscriptions,code',
            'subscriber_first_name' => 'required|string',
            'subscriber_last_name' => 'nullable|string',
            'subscriber_address' => 'required|string',
            'subscriber_province' => 'required|string',
            'subscriber_zone' => 'required|string',
            'subscriber_phone' => 'nullable|string|max:30',
            'subscriber_note'  => 'nullable|string',
            'subscriber_delivery_slot' => 'nullable|in:09:00-12:00,12:00-15:00',
            'shopify_customer_id' => 'nullable|string|max:100',
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
        ]);

        $validator->after(function ($validator) use ($request) {
            $animals = $request->input('animals', []);
            foreach ($animals as $index => $animal) {
                $species = $animal['species'] ?? null;
                $breedSizeCategory = $animal['breed_size_category'] ?? null;

                if ($species === 'Dog' && empty($breedSizeCategory)) {
                    $validator->errors()->add("animals.$index.breed_size_category", 'The breed size category field is required when species is Dog.');
                }
            }
        });

        $validator->validate();

        $animalsInput = $request->input('animals', []);

        DB::transaction(function () use ($request, $animalsInput, $subscriptionCode) {
            $subscription = Subscription::create([
                'code' => $subscriptionCode,
                'creation_date' => now(),
                'subscriber_first_name' => $request->subscriber_first_name,
                'subscriber_last_name' => $request->subscriber_last_name,
                'subscriber_address' => $request->subscriber_address,
                'subscriber_province' => $request->subscriber_province,
                'subscriber_zone' => $request->subscriber_zone,
                'subscriber_delivery_slot' => $request->subscriber_delivery_slot,
                'delivery_days' => [],
                'day_recipes' => [],
                'subscriber_phone' => $request->subscriber_phone,
                'subscriber_note'  => $request->subscriber_note,
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
            $this->ensureCustomerAccount(
                $subscription
            );
        });

        return redirect()->route('subscriptions.index')->with('success', 'Subscription created successfully.');
    }

    public function edit(Subscription $subscription)
    {
        $subscription->load([
            'customerAccount',
            'animals.mealDates.recipe',
            'plannedOrders.items.recipe',
            'animals.plannedOrders.items.recipe'
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
        $allowedDeliverySlots = [
            '09:00-12:00',
            '12:00-15:00',
        ];

        if (!empty($subscription->subscriber_delivery_slot)) {
            $allowedDeliverySlots[] = $subscription->subscriber_delivery_slot;
        }

        $allowedDeliverySlots = array_values(array_unique(array_filter($allowedDeliverySlots)));

        $validator = Validator::make($request->all(), [
            'code' => 'required|unique:subscriptions,code,' . $subscription->id,
            'subscriber_first_name' => 'required|string',
            'subscriber_last_name' => 'nullable|string',
            'subscriber_address' => 'required|string',
            'subscriber_province' => 'required|string',
            'subscriber_zone' => 'required|string',
            'subscriber_phone' => 'nullable|string|max:30',
            'subscriber_note'  => 'nullable|string',
            'subscriber_delivery_slot' => 'nullable|in:' . implode(',', $allowedDeliverySlots),

            'animals' => 'required|array|min:1',
            'animals.*.id' => 'nullable|integer',
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
        ]);

        $validator->after(function ($validator) use ($request) {
            $animals = $request->input('animals', []);
            foreach ($animals as $index => $animal) {
                $species = $animal['species'] ?? null;
                $breedSizeCategory = $animal['breed_size_category'] ?? null;

                if ($species === 'Dog' && empty($breedSizeCategory)) {
                    $validator->errors()->add("animals.$index.breed_size_category", 'The breed size category field is required when species is Dog.');
                }
            }
        });

        $validator->validate();

        $animalsInput = $request->input('animals', []);

        DB::transaction(function () use ($request, $subscription, $animalsInput) {
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
                'subscriber_address' => $request->subscriber_address,
                'subscriber_province' => $request->subscriber_province,
                'subscriber_zone' => $request->subscriber_zone,
                'subscriber_delivery_slot' => $request->subscriber_delivery_slot,
                'delivery_days' => [],
                'day_recipes' => [],
                'subscriber_phone' => $request->subscriber_phone,
                'subscriber_note' => $request->subscriber_note,
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
                |
                | Preferred method: hidden animals[index][id] field from edit form.
                |
                | Backward-compatible fallback:
                | if the current Blade does not yet submit the ID, use the animal
                | at the same position. This prevents existing installations from
                | immediately recreating all pets, but the hidden ID is strongly
                | recommended.
                |
                */

                $animal = null;

                if (
                    $submittedAnimalId
                    &&
                    $existingAnimals->has(
                        $submittedAnimalId
                    )
                ) {
                    $animal =
                        $existingAnimals->get(
                            $submittedAnimalId
                        );
                } elseif (
                    !$submittedAnimalId
                    &&
                    isset(
                        $subscription
                            ->animals[$index]
                    )
                ) {
                    $animal =
                        $subscription
                            ->animals[$index];
                }

                /*
                |--------------------------------------------------------------------------
                | QR CODE IMAGES
                |--------------------------------------------------------------------------
                */

                $qrCodeImagePath =
                    $animal?->qr_code_image;

                $lastOrderQrCodeImagePath =
                    $animal?->last_order_qr_code_image;

                /*
                |--------------------------------------------------------------------------
                | CUSTOMER PET PHOTO
                |--------------------------------------------------------------------------
                |
                | Keep the customer-uploaded photo by default.
                | Replace it only when the admin explicitly uploads a new photo.
                |
                */
                $photoPath =
                    $animal?->photo_path;

                if (
                    $request->hasFile(
                        "animals.$index.photo"
                    )
                ) {
                    if (
                        !empty($photoPath)
                        &&
                        Storage::disk('public')
                            ->exists($photoPath)
                    ) {
                        Storage::disk('public')
                            ->delete($photoPath);
                    }

                    $photoPath =
                        $request
                            ->file(
                                "animals.$index.photo"
                            )
                            ->store(
                                'pets/' . (
                                    $animal
                                        ? $animal->id
                                        : 'admin'
                                ),
                                'public'
                            );
                }

                if ($transitionEnabled) {
                    if (
                        $request->hasFile(
                            "animals.$index.qr_code_image"
                        )
                    ) {
                        if (
                            !empty($qrCodeImagePath)
                            &&
                            Storage::disk('public')
                                ->exists($qrCodeImagePath)
                        ) {
                            Storage::disk('public')
                                ->delete($qrCodeImagePath);
                        }

                        $qrCodeImagePath =
                            $request
                                ->file(
                                    "animals.$index.qr_code_image"
                                )
                                ->store(
                                    'animal_qr_codes',
                                    'public'
                                );
                    } else {
                        $existingPath =
                            $animalData[
                                'existing_qr_code_image'
                            ] ?? null;

                        if (!empty($existingPath)) {
                            $qrCodeImagePath =
                                $existingPath;
                        }
                    }
                } else {
                    if (!empty($qrCodeImagePath)) {
                        Storage::disk('public')
                            ->delete($qrCodeImagePath);
                    }

                    $qrCodeImagePath = null;
                }

                if ($lastOrderEnabled) {
                    if (
                        $request->hasFile(
                            "animals.$index.last_order_qr_code_image"
                        )
                    ) {
                        if (
                            !empty(
                                $lastOrderQrCodeImagePath
                            )
                            &&
                            Storage::disk('public')
                                ->exists(
                                    $lastOrderQrCodeImagePath
                                )
                        ) {
                            Storage::disk('public')
                                ->delete(
                                    $lastOrderQrCodeImagePath
                                );
                        }

                        $lastOrderQrCodeImagePath =
                            $request
                                ->file(
                                    "animals.$index.last_order_qr_code_image"
                                )
                                ->store(
                                    'animal_qr_codes',
                                    'public'
                                );
                    } else {
                        $existingPath =
                            $animalData[
                                'existing_last_order_qr_code_image'
                            ] ?? null;

                        if (!empty($existingPath)) {
                            $lastOrderQrCodeImagePath =
                                $existingPath;
                        }
                    }
                } else {
                    if (
                        !empty(
                            $lastOrderQrCodeImagePath
                        )
                    ) {
                        Storage::disk('public')
                            ->delete(
                                $lastOrderQrCodeImagePath
                            );
                    }

                    $lastOrderQrCodeImagePath = null;
                }

                $species =
                    $animalData['species'];

                $breedSizeCategory =
                    $species === 'Dog'
                        ? (
                            $animalData[
                                'breed_size_category'
                            ] ?? null
                        )
                        : null;

                $animalValues = [
                    'name' =>
                        $animalData['name'],

                    'species' =>
                        $species,

                    'breed_size_category' =>
                        $breedSizeCategory,

                    'age' =>
                        $animalData['age']
                            ?? null,

                    'health_status' =>
                        $animalData[
                            'health_status'
                        ] ?? null,

                    'transition' =>
                        $transitionEnabled
                            ? 1
                            : 0,

                    'note' =>
                        $animalData['note']
                            ?? null,

                    'photo_path' =>
                        $photoPath,

                    'qr_code_image' =>
                        $transitionEnabled
                            ? $qrCodeImagePath
                            : null,

                    'last_order' =>
                        $lastOrderEnabled
                            ? 1
                            : 0,

                    'last_order_qr_code_image' =>
                        $lastOrderEnabled
                            ? $lastOrderQrCodeImagePath
                            : null,
                ];

                if ($animal) {
                    /*
                     * Existing pet:
                     * update in place.
                     *
                     * photo_path is intentionally NOT included here.
                     * Therefore the photo managed by the customer app
                     * remains untouched.
                     */
                    $animal->update(
                        $animalValues
                    );
                } else {
                    /*
                     * New pet created from subscription admin.
                     */
                    $animal =
                        $subscription
                            ->animals()
                            ->create(
                                array_merge(
                                    $animalValues,
                                    [
                                        'subscription_start' =>
                                            null,

                                        'subscription_end' =>
                                            null,
                                    ]
                                )
                            );
                }

                $processedAnimalIds[] =
                    (int) $animal->id;

                /*
                |--------------------------------------------------------------------------
                | MEALS
                |--------------------------------------------------------------------------
                |
                | Rebuild only meal dates for this same Animal ID.
                |
                */

                $animal
                    ->mealDates()
                    ->delete();

                $dayMeals =
                    $animalData[
                        'day_meals'
                    ] ?? [];

                $this->persistMealsByDate(
                    $animal,
                    $dayMeals
                );

                $this->syncPlannedOrders(
                    $subscription,
                    $animal,
                    $dayMeals
                );
            }

            /*
            |--------------------------------------------------------------------------
            | REMOVE ANIMALS DELETED FROM ADMIN FORM
            |--------------------------------------------------------------------------
            */

            $animalsToDelete =
                $existingAnimals
                    ->filter(
                        function (
                            Animal $animal
                        ) use (
                            $processedAnimalIds
                        ) {
                            return !in_array(
                                (int) $animal->id,
                                $processedAnimalIds,
                                true
                            );
                        }
                    );

            foreach (
                $animalsToDelete
                as $animalToDelete
            ) {
                /*
                 * Delete customer photo only because this pet
                 * is really being removed from the subscription.
                 */
                if (
                    !empty(
                        $animalToDelete->photo_path
                    )
                ) {
                    Storage::disk('public')
                        ->delete(
                            $animalToDelete
                                ->photo_path
                        );
                }

                if (
                    !empty(
                        $animalToDelete
                            ->qr_code_image
                    )
                ) {
                    Storage::disk('public')
                        ->delete(
                            $animalToDelete
                                ->qr_code_image
                        );
                }

                if (
                    !empty(
                        $animalToDelete
                            ->last_order_qr_code_image
                    )
                ) {
                    Storage::disk('public')
                        ->delete(
                            $animalToDelete
                                ->last_order_qr_code_image
                        );
                }

                $animalToDelete
                    ->mealDates()
                    ->delete();

                /*
                 * Do not silently destroy an animal that already has
                 * planned order history. In that case keep it instead.
                 */
                if (
                    !$animalToDelete
                        ->plannedOrders()
                        ->exists()
                ) {
                    $animalToDelete->delete();
                }
            }

            $this->ensureCustomerAccount(
                $subscription
            );
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

    private function syncPlannedOrders(Subscription $subscription, Animal $animal, $dayMeals)
    {
        if (!is_array($dayMeals)) return;

        $lockedStatuses = ['prepared', 'shipped', 'delivered'];

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

            if (!count($items)) {
                $order = PlannedOrder::where('animal_id', $animal->id)->where('scheduled_for', $date)->first();
                if ($order && !in_array($order->status, $lockedStatuses, true)) {
                    $order->items()->delete();
                    $order->delete();
                }
                continue;
            }

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
        |
        | Keep the account and synchronize its phone with the subscription.
        |
        */
        if ($subscription->customerAccount) {
            $customerAccount = $subscription->customerAccount;

            $phoneUsedByAnotherAccount =
                CustomerAccount::query()
                    ->where(
                        'phone',
                        $normalizedPhone
                    )
                    ->where(
                        'id',
                        '<>',
                        $customerAccount->id
                    )
                    ->exists();

            if ($phoneUsedByAnotherAccount) {
                throw ValidationException::withMessages([
                    'subscriber_phone' =>
                        'This phone number is already linked to another customer account.',
                ]);
            }

            if (
                (string) $customerAccount->phone
                !== $normalizedPhone
            ) {
                $customerAccount->phone =
                    $normalizedPhone;

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
                ->where(
                    'phone',
                    $normalizedPhone
                )
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'subscriber_phone' =>
                    'This phone number is already linked to another customer account.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate initial 6-digit password
        |--------------------------------------------------------------------------
        */

        $initialPassword =
            $this->generateInitialCustomerPassword();

        /*
        |--------------------------------------------------------------------------
        | Create customer dashboard account automatically
        |--------------------------------------------------------------------------
        */

        return CustomerAccount::create([
            'subscription_id' =>
                $subscription->id,

            'phone' =>
                $normalizedPhone,

            'password' =>
                    $initialPassword,

            'initial_password_encrypted' =>
                Crypt::encryptString(
                    $initialPassword
                ),

            'password_changed_at' =>
                null,
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
                function () use (
                    $subscription
                ) {
                    $subscription->loadMissing(
                        'customerAccount'
                    );

                    $normalizedPhone =
                        $this->normalizeCustomerPhone(
                            $subscription->subscriber_phone
                        );

                    if ($normalizedPhone === '') {
                        return response()->json([
                            'success' => false,

                            'message' =>
                                'Phone number is required to initialize the customer account.',
                        ], 422);
                    }

                    $initialPassword =
                        $this->generateInitialCustomerPassword();

                    $customerAccount =
                        $subscription->customerAccount;

                    /*
                    |--------------------------------------------------------------------------
                    | Create missing account
                    |--------------------------------------------------------------------------
                    */

                    if (!$customerAccount) {
                        $phoneUsedByAnotherAccount =
                            CustomerAccount::query()
                                ->where(
                                    'phone',
                                    $normalizedPhone
                                )
                                ->exists();

                        if ($phoneUsedByAnotherAccount) {
                            return response()->json([
                                'success' => false,

                                'message' =>
                                    'This phone number is already linked to another customer account.',
                            ], 422);
                        }

                        $customerAccount =
                            CustomerAccount::create([
                                'subscription_id' =>
                                    $subscription->id,

                                'phone' =>
                                    $normalizedPhone,

                                'password' =>
                                        $initialPassword,

                                'initial_password_encrypted' =>
                                    Crypt::encryptString(
                                        $initialPassword
                                    ),

                                'password_changed_at' =>
                                    null,

                                /*
                                 * No previous authenticated session
                                 * should survive initialization.
                                 */
                                'access_token_hash' =>
                                    null,

                                'access_token_expires_at' =>
                                    null,
                            ]);
                    } else {
                        /*
                        |--------------------------------------------------------------------------
                        | Existing account: reset credentials
                        |--------------------------------------------------------------------------
                        */

                        $phoneUsedByAnotherAccount =
                            CustomerAccount::query()
                                ->where(
                                    'phone',
                                    $normalizedPhone
                                )
                                ->where(
                                    'id',
                                    '<>',
                                    $customerAccount->id
                                )
                                ->exists();

                        if ($phoneUsedByAnotherAccount) {
                            return response()->json([
                                'success' => false,

                                'message' =>
                                    'This phone number is already linked to another customer account.',
                            ], 422);
                        }

                        $customerAccount->phone =
                            $normalizedPhone;

                        $customerAccount->password =
                                $initialPassword;

                        $customerAccount
                            ->initial_password_encrypted =
                                Crypt::encryptString(
                                    $initialPassword
                                );

                        $customerAccount
                            ->password_changed_at =
                                null;

                        /*
                         * Force login again on all devices after reset.
                         */
                        $customerAccount
                            ->access_token_hash =
                                null;

                        $customerAccount
                            ->access_token_expires_at =
                                null;

                        $customerAccount->save();
                    }

                    return response()->json([
                        'success' =>
                            true,

                        'message' =>
                            'Customer account initialized successfully.',

                        'customer_account_id' =>
                            $customerAccount->id,

                        'initial_password' =>
                            $initialPassword,
                    ]);
                }
            );

        } catch (Throwable $exception) {
            report(
                $exception
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to initialize customer account.',
            ], 500);
        }
    }


    /**
     * Generate a cryptographically secure 6-digit password.
     */
    private function generateInitialCustomerPassword(): string
    {
        return (string) random_int(
            100000,
            999999
        );
    }


private function normalizeCustomerPhone(
    ?string $phone
): string {
    return preg_replace(
        '/[^0-9]/',
        '',
        (string) $phone
    ) ?? '';
}

    private function generateNextSubscriptionCode(?string $provinceName = null): string
    {
        $provinceCode = '00';

        if ($provinceName) {
            $province = Province::where('name', $provinceName)->first();
            if ($province && $province->code) {
                $provinceCode = str_pad($province->code, 2, '0', STR_PAD_LEFT);
            }
        }

        $lastSubscription = Subscription::where('code', 'REGEXP', '^C[0-9]{7}$')
            ->orderByRaw('CAST(SUBSTRING(code, 4) AS UNSIGNED) DESC')
            ->first();

        $nextNumber = 1;

        if ($lastSubscription) {
            $lastNumber = (int) substr($lastSubscription->code, 3);
            $nextNumber = $lastNumber + 1;
        }

        return 'C' . $provinceCode . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
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

<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\LoyaltySetting;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Zone;
use App\Services\PetLoyaltyService;
use App\Support\CustomerSession;
use App\Support\ProductPricing;
use App\Support\PremiumPack;
use App\Support\ShopCatalog;
use App\Support\SubscriptionCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Shop of the mobile app.
 *
 *  POST /api/mobile/shop/products   PUBLIC  { lang, species?, access_token? }
 *  POST /api/mobile/shop/products/{product}  PUBLIC (one product)
 *  POST /api/mobile/shop/checkout   SIGNED IN
 *
 * Checkout = buy in one step, for one or several pets:
 *   - items[]: one line per pet = pack (age version) + existing pet
 *     (animal_id) or new pet (age chosen) + number of packs (1-3,
 *     the next ones follow for the same pet)
 *   - 2 pets or more: multi-pet discount on every pack
 *   - first address created when the customer has none
 *   - items[].use_points: the points of the pet lower the price of its
 *     first pack (50 min, 150 max, never more than the price)
 *   - then the purchase itself (MobilePurchaseController::store:
 *     same rules, promo / trial offer prices, limits)
 */
class MobileShopController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | CATALOG (PUBLIC)
    |--------------------------------------------------------------------------
    */

    public function products(Request $request): JsonResponse
    {
        $lang = $this->lang($request);
        $customer = CustomerSession::fromRequest($request);

        $species = in_array($request->input('species'), Product::SPECIES, true)
            ? $request->input('species')
            : null;

        /*
         * Only TP- / REG- / PREMIUM- products (the products made for one
         * premium customer are never shown), in the order that fits what
         * the customer already bought (see App\Support\ShopCatalog).
         */
        $all = ShopCatalog::arrange(
            ShopCatalog::onlyShopCodes(Product::query()->active())
                ->with('recipes')
                ->ordered()
                ->get(),
            ShopCatalog::historyFor($customer)
        );

        /*
         * Product opened from the renew plans page of ONE pet (checkout):
         * always returned, even when the history of the customer hides it
         * (e.g. a taste pack for his other, non premium, pet).
         */
        if ($request->filled('include_product_id') && !$all->contains('id', (int) $request->input('include_product_id'))) {
            $extra = Product::query()->active()->with('recipes')->find((int) $request->input('include_product_id'));

            if ($extra && ShopCatalog::isShopCode($extra->code)) {
                $all = $all->push($extra)->values();
            }
        }

        $products = $species ? $all->where('species', $species)->values() : $all;

        $pets = $customer ? $this->customerPets($customer) : collect();

        // What each pet already bought (premium pets never get a taste pack)
        $petHistories = $pets->mapWithKeys(fn (Animal $animal) => [
            $animal->id => ShopCatalog::historyFor($customer, $animal->id),
        ])->all();

        return response()->json([
            'success' => true,
            'signed_in' => (bool) $customer,
            'products' => $products->map(fn (Product $product) => $this->formatProduct($product, $lang, $pets, $petHistories))->values(),
            'counts' => [
                'Cat' => $all->where('species', 'Cat')->count(),
                'Dog' => $all->where('species', 'Dog')->count(),
            ],
            'pets' => $pets->map(fn (Animal $animal) => $this->formatPet($animal, $lang))->values(),
            'has_address' => $customer ? $customer->addresses()->exists() : false,
            // Shop: card payment only (KNET, Visa / Mastercard)
            'payment_methods' => collect(MobilePurchaseController::CARD_PAYMENT_METHODS)->map(fn ($method) => [
                'value' => $method,
                'label' => $this->paymentLabel($method, $lang),
            ])->values(),
            'delivery_slots' => CustomerAddress::DELIVERY_SLOTS,
            // Several pets in one order: discount on every pack
            'multi_pet_discount' => self::multiPetDiscount(),
            'max_pets' => self::MAX_PETS,
            'max_packs_per_pet' => self::MAX_PACKS_PER_PET,
            // Loyalty points (per pet: see pets[].loyalty)
            'loyalty_program' => $this->loyaltyProgram(),
        ]);
    }

    public function product(Request $request, Product $product): JsonResponse
    {
        $lang = $this->lang($request);

        if (!$product->is_active || !ShopCatalog::isShopCode($product->code)) {
            return response()->json([
                'success' => false,
                'message' => $lang === 'ar' ? 'هذا المنتج غير متوفر.' : 'This product is not available.',
            ], 404);
        }

        $customer = CustomerSession::fromRequest($request);
        $pets = $customer ? $this->customerPets($customer) : collect();

        $product->loadMissing('recipes');

        return response()->json([
            'success' => true,
            'signed_in' => (bool) $customer,
            'product' => $this->formatProduct($product, $lang, $pets),
            'pets' => $pets->map(fn (Animal $animal) => $this->formatPet($animal, $lang))->values(),
            'has_address' => $customer ? $customer->addresses()->exists() : false,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CHECKOUT (SIGNED IN)
    |--------------------------------------------------------------------------
    |
    | {
    |   access_token, lang, payment_method, customer_note?, card_paid?, card_last4?,
    |   items: [                                    one line per pet
    |     { product_id,                             pack (age version)
    |       animal_id? | pet: { name?, age, breed_size_category? },
    |       quantity? (1-3: packs one after the other for this pet) }
    |   ],
    |   address_id?                                 or first address:
    |   address: { province_id, zone_id, address, delivery_slot, note?, label? }
    | }
    | Old format (product_id + animal_id / pet) still accepted.
    |
    */

    public function checkout(Request $request): JsonResponse
    {
        $lang = $this->lang($request);
        $customer = CustomerSession::fromRequest($request);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'requires_login' => true,
                'message' => $lang === 'ar' ? 'يرجى تسجيل الدخول للمتابعة.' : 'Please sign in to continue.',
            ], 401);
        }

        /*
         * Old app versions: one product + one pet -> one line
         */
        if (!$request->has('items')) {
            $request->merge(['items' => [[
                'product_id' => $request->input('product_id'),
                'animal_id' => $request->input('animal_id'),
                'pet' => $request->input('pet'),
                'quantity' => 1,
            ]]]);
        }

        $validator = Validator::make($request->all(), [
            'payment_method' => ['required', Rule::in(MobilePurchaseController::CARD_PAYMENT_METHODS)],
            'customer_note' => ['nullable', 'string', 'max:1000'],

            // Card already "paid" in the app (simulation): purchases paid at once
            'card_paid' => ['nullable', 'boolean'],
            'card_last4' => ['nullable', 'digits:4'],

            // One line per pet: pack (age version) + existing or new pet + number of packs
            'items' => ['required', 'array', 'min:1', 'max:' . self::MAX_PETS],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.animal_id' => ['nullable', 'integer'],
            'items.*.pet' => ['nullable', 'array'],
            'items.*.pet.name' => ['nullable', 'string', 'max:100'],
            'items.*.pet.age' => ['nullable', Rule::in(Product::LIFE_STAGES)],
            'items.*.pet.breed_size_category' => ['nullable', Rule::in(Product::BREED_SIZES)],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PACKS_PER_PET],
            'items.*.use_points' => ['nullable', 'boolean'],

            'address_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'array'],
            'address.province_id' => ['nullable', 'integer'],
            'address.zone_id' => ['nullable', 'integer'],
            'address.address' => ['nullable', 'string', 'max:500'],
            'address.delivery_slot' => ['nullable', Rule::in(CustomerAddress::DELIVERY_SLOTS)],
            'address.note' => ['nullable', 'string', 'max:1000'],
            'address.label' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        if (!$customer->subscription_id) {
            return $this->error($lang === 'ar' ? 'حسابك غير مكتمل. تواصل مع الدعم.' : 'Your account is incomplete. Please contact support.', 422);
        }

        /*
         * 1. Lines: pack + pet (existing one, or the data of a new one)
         */
        $lines = [];
        $usedPets = [];

        foreach (array_values($request->input('items', [])) as $index => $item) {
            $product = Product::query()->active()->find((int) ($item['product_id'] ?? 0));

            if (!$product || !ShopCatalog::isShopCode($product->code)) {
                return $this->error($lang === 'ar' ? 'هذا المنتج غير متوفر.' : 'This product is not available.', 422);
            }

            $line = [
                'product' => $product,
                'quantity' => max(1, min(self::MAX_PACKS_PER_PET, (int) ($item['quantity'] ?? 1))),
                'existing' => null,
                'new' => null,
                // Points of the pet on its first pack (existing pets only)
                'use_points' => !empty($item['animal_id']) && filter_var($item['use_points'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];

            if (!empty($item['animal_id'])) {
                $pet = Animal::where('subscription_id', $customer->subscription_id)->find((int) $item['animal_id']);

                if (!$pet || $pet->species !== $product->species) {
                    return $this->error($lang === 'ar' ? 'الحيوان غير موجود.' : 'Pet not found.', 422);
                }

                if (isset($usedPets[$pet->id])) {
                    return $this->error($lang === 'ar' ? 'تم اختيار نفس الحيوان مرتين.' : 'The same pet is chosen twice.', 422);
                }

                $usedPets[$pet->id] = true;
                $line['existing'] = $pet;
            } else {
                $age = $item['pet']['age'] ?? null;

                if (!in_array($age, $this->allowedAges($product), true)) {
                    return $this->error(
                        $lang === 'ar' ? 'يرجى اختيار عمر حيوانك.' : 'Please choose the age of your pet.',
                        422,
                        ["items.{$index}.pet.age" => ['required']]
                    );
                }

                $size = null;

                if ($product->species === 'Dog') {
                    $size = $product->breed_size_category ?: ($item['pet']['breed_size_category'] ?? null);

                    if (!$size) {
                        return $this->error(
                            $lang === 'ar' ? 'يرجى اختيار حجم الكلب.' : 'Please choose the size of your dog.',
                            422,
                            ["items.{$index}.pet.breed_size_category" => ['required']]
                        );
                    }
                }

                $name = trim((string) ($item['pet']['name'] ?? ''));

                $line['new'] = [
                    'name' => $name !== '' ? $name : $this->defaultPetName($product->species, $lang, $index),
                    'species' => $product->species,
                    'age' => $age,
                    'breed_size_category' => $size,
                ];
            }

            $lines[] = $line;
        }

        /*
         * 2. Address: NOT asked at checkout any more. The customer adds it
         *    when he plans his deliveries. Old app versions can still send
         *    a first address: it is saved when complete.
         */
        $hasAddress = $customer->addresses()->exists();
        $zone = null;
        $sentAddress = $request->filled('address.zone_id') || $request->filled('address.address');

        if (!$hasAddress && $sentAddress) {
            $zone = Zone::with('province')->find($request->integer('address.zone_id'));

            $addressError = match (true) {
                !$zone || (int) $zone->province_id !== $request->integer('address.province_id')
                    => $lang === 'ar' ? 'يرجى اختيار المحافظة والمنطقة.' : 'Please select your governorate and area.',
                !filled($request->input('address.address'))
                    => $lang === 'ar' ? 'يرجى إدخال العنوان.' : 'Please enter your address.',
                !filled($request->input('address.delivery_slot'))
                    => $lang === 'ar' ? 'يرجى اختيار وقت التوصيل.' : 'Please select a delivery time slot.',
                default => null,
            };

            if ($addressError) {
                return $this->error($addressError, 422, ['address' => ['required']]);
            }
        } elseif ($request->filled('address_id')
            && !$customer->addresses()->whereKey($request->integer('address_id'))->exists()) {
            return $this->error($lang === 'ar' ? 'العنوان غير موجود.' : 'Address not found.', 422);
        }

        /*
         * 3. Address + new pets
         */
        try {
            $createdPets = DB::transaction(function () use ($customer, $request, $zone, $hasAddress, &$lines) {
                if (!$hasAddress && $zone) {
                    $this->createFirstAddress($customer, $zone, $request->input('address', []));
                } elseif ($request->filled('address_id')) {
                    // Chosen address becomes the default one (deliveries use it)
                    $customer->addresses()->update(['is_default' => false]);
                    $customer->addresses()->whereKey($request->integer('address_id'))->update(['is_default' => true]);
                }

                $created = [];

                foreach ($lines as $i => $line) {
                    if ($line['new']) {
                        $pet = Animal::create($line['new'] + [
                            'subscription_id' => $customer->subscription_id,
                            'health_status' => null,
                            'transition' => false,
                            'last_order' => false,
                            'subscription_start' => null,
                            'subscription_end' => null,
                        ]);

                        $lines[$i]['existing'] = $pet;
                        $created[] = $pet;
                    }
                }

                return $created;
            });
        } catch (Throwable $exception) {
            report($exception);

            return $this->error($lang === 'ar' ? 'تعذر إكمال الطلب.' : 'Unable to complete the order.', 500);
        }

        /*
         * 4. Purchases: one per pack (same rules as the plans page),
         *    then the multi-pet discount on every pack of the order.
         */
        $discount = self::multiPetDiscount();
        $discountActive = $discount['percent'] > 0 && count($lines) >= $discount['min_pets'];
        $purchases = [];

        $loyalty = app(PetLoyaltyService::class);
        $pointsUsed = [];

        foreach ($lines as $line) {
            for ($unit = 1; $unit <= $line['quantity']; $unit++) {
                $request->merge([
                    'animal_id' => $line['existing']->id,
                    'product_id' => $line['product']->id,
                    '_shop_multi' => true,
                ]);

                try {
                    $response = app(MobilePurchaseController::class)->store($request);
                } catch (Throwable $exception) {
                    report($exception);
                    $response = null;
                }

                $data = $response ? $response->getData(true) : [];
                $purchase = Purchase::find($data['purchase']['id'] ?? null);

                if (!$response || $response->getStatusCode() >= 300 || !($data['success'] ?? false) || !$purchase) {
                    $this->rollbackOrder($purchases, $createdPets);

                    return response()->json(
                        $data ?: ['success' => false, 'message' => $lang === 'ar' ? 'تعذر إكمال الطلب.' : 'Unable to complete the order.'],
                        $response && $response->getStatusCode() >= 400 ? $response->getStatusCode() : 422
                    );
                }

                if ($discountActive && (!$purchase->is_trial || $discount['applies_to_trial'])) {
                    $this->applyMultiPetDiscount($purchase, $discount['percent'], count($lines));
                }

                // Loyalty points of the pet: on its first pack of the order only
                if ($line['use_points'] && $unit === 1) {
                    try {
                        $pointsUsed[$purchase->id] = $loyalty->applyToNewPurchase($purchase->fresh());
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }

                $purchases[] = $purchase->fresh();
            }
        }

        /*
         * 5. Card payment done in the app (simulation): all the packs paid at
         *    once -> the customer can plan the deliveries immediately.
         */
        $paid = false;

        if ($request->boolean('card_paid') && MobilePurchaseController::simulationEnabled()) {
            $paid = true;

            foreach ($purchases as $purchase) {
                try {
                    MobilePurchaseController::markCardPaid($purchase, $request->input('payment_method'), $request->input('card_last4'));
                    $paid = $paid && $purchase->fresh()->status === 'paid';
                } catch (Throwable $exception) {
                    $paid = false;
                    report($exception);
                    Log::error('Shop checkout: simulated payment failed', ['purchase_id' => $purchase->id, 'error' => $exception->getMessage()]);
                }
            }
        }

        $purchases = collect($purchases)->map->fresh();
        $first = $purchases->first();

        // Paid: points earned by each pack (recalculated now)
        if ($paid) {
            $purchases->pluck('animal_id')->unique()->each(fn ($id) => $loyalty->rebuildPetSafely((int) $id));
        }

        $loyaltyOf = fn (Purchase $p) => [
            'points_used' => (int) ($pointsUsed[$p->id] ?? 0),
            'discount' => (float) ($loyalty->pointsUsedOn($p->id)['amount'] ?? 0),
            'points_earned' => $paid ? $loyalty->pointsEarnedBy($p->id) : 0,
        ];

        return response()->json([
            'success' => true,
            'paid' => $paid,
            'message' => $paid
                ? ($lang === 'ar' ? 'تم الدفع بنجاح.' : 'Payment successful.')
                : ($lang === 'ar' ? 'تم تسجيل طلبك.' : 'Your order is registered.'),

            // First purchase (old app versions)
            'purchase' => ['id' => $first->id, 'status' => $first->status, 'reference' => $first->reference],
            'animal' => $this->formatPet($lines[0]['existing']->fresh(), $lang),
            'animal_created' => count($createdPets) > 0,

            // Whole order
            'purchases' => $purchases->map(fn (Purchase $p) => [
                'id' => $p->id,
                'reference' => $p->reference,
                'status' => $p->status,
                'product_name' => $p->localizedProductName($lang),
                'animal_id' => $p->animal_id,
                'animal_name' => $p->animal_name,
                'unit_price' => (float) $p->unit_price,
                'discount_amount' => (float) $p->discount_amount,
                'total_amount' => (float) $p->total_amount,
                'loyalty' => $loyaltyOf($p),
            ])->values(),
            'pets_count' => count($lines),
            'subtotal_amount' => round((float) $purchases->sum('unit_price'), 3),
            'discount_amount' => round((float) $purchases->sum('discount_amount'), 3),
            'total_amount' => round((float) $purchases->sum('total_amount'), 3),
            'currency' => $first->currency,
            'loyalty_points_used' => array_sum($pointsUsed),
            'loyalty_discount' => round((float) $purchases->sum(fn (Purchase $p) => $loyalty->pointsUsedOn($p->id)['amount']), 3),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | MULTI-PET DISCOUNT
    |--------------------------------------------------------------------------
    |
    | Several pets in one order: X % on every pack (SHOP_MULTI_PET_DISCOUNT_PERCENT,
    | 14.2857 = 35 -> 30 KWD). Trial packs excluded unless
    | SHOP_MULTI_PET_DISCOUNT_TRIAL=true.
    |
    */

    public const MAX_PETS = 6;
    public const MAX_PACKS_PER_PET = 3;

    public static function multiPetDiscount(): array
    {
        return [
            'percent' => max(0, min(90, (float) config('services.shop.multi_pet_discount_percent', 0))),
            'min_pets' => max(2, (int) config('services.shop.multi_pet_discount_min_pets', 2)),
            'applies_to_trial' => (bool) config('services.shop.multi_pet_discount_trial', false),
        ];
    }

    private function applyMultiPetDiscount(Purchase $purchase, float $percent, int $pets): void
    {
        $price = (float) $purchase->unit_price;
        $amount = round($price * $percent / 100, 3);

        $purchase->forceFill([
            'discount_amount' => round((float) $purchase->discount_amount + $amount, 3),
            'total_amount' => max(0, round((float) $purchase->total_amount - $amount, 3)),
            'admin_note' => trim(($purchase->admin_note ? $purchase->admin_note . "\n" : '')
                . "Multi-pet discount ({$pets} pets): -{$percent}% (-" . number_format($amount, 3) . ' ' . $purchase->currency . ').'),
        ])->save();
    }

    /** Order refused half way: purchases not paid and new pets removed. */
    private function rollbackOrder(array $purchases, array $createdPets): void
    {
        foreach ($purchases as $purchase) {
            try {
                DB::table('purchase_status_histories')->where('purchase_id', $purchase->id)->delete();
                $purchase->delete();
            } catch (Throwable $exception) {
                Log::warning('Shop checkout: unable to remove purchase', ['purchase_id' => $purchase->id]);
            }
        }

        foreach ($createdPets as $pet) {
            try {
                $pet->delete();
            } catch (Throwable $exception) {
                Log::warning('Shop checkout: unable to remove pet', ['animal_id' => $pet->id]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * First address of a customer created in the app (no address at sign up):
     * becomes the default address and fills the subscription
     * (address, governorate, area, slot, province digits of the code).
     */
    private function createFirstAddress(CustomerAccount $customer, Zone $zone, array $input): void
    {
        $subscription = $customer->subscription;

        CustomerAddress::create([
            'customer_account_id' => $customer->id,
            'label' => $input['label'] ?? null,
            'first_name' => $subscription?->subscriber_first_name,
            'last_name' => $subscription?->subscriber_last_name,
            'phone' => $customer->phone,
            'address' => trim((string) $input['address']),
            'note' => $input['note'] ?? null,
            'delivery_slot' => $input['delivery_slot'] ?? null,
            'province_id' => $zone->province_id,
            'zone_id' => $zone->id,
            'is_default' => true,
        ]);

        if ($subscription) {
            $subscription->fill([
                'subscriber_address' => trim((string) $input['address']),
                'subscriber_note' => $input['note'] ?? $subscription->subscriber_note,
                'subscriber_province' => $zone->province?->name,
                'subscriber_zone' => $zone->name,
                'subscriber_delivery_slot' => $input['delivery_slot'] ?? null,
            ]);

            if (SubscriptionCode::hasNoProvince($subscription->code)) {
                $subscription->code = SubscriptionCode::withProvince($subscription->code, $zone->province);
            }

            $subscription->save();
        }
    }

    private function customerPets(CustomerAccount $customer)
    {
        if (!$customer->subscription_id) {
            return collect();
        }

        return Animal::where('subscription_id', $customer->subscription_id)->orderBy('id')->get();
    }

    /**
     * Ages the customer can choose for a new pet of this pack.
     */
    private function allowedAges(Product $product): array
    {
        $stages = $product->lifeStagesList();

        return $stages ?: (Product::LIFE_STAGES_BY_SPECIES[$product->species] ?? Product::LIFE_STAGES);
    }

    private function formatProduct(Product $product, string $lang, $pets, array $petHistories = []): array
    {
        // Pets that can receive this pack (species, size, age, premium pets: no taste pack)
        $compatiblePets = $pets->filter(function (Animal $animal) use ($product, $petHistories) {
            if ($animal->species !== $product->species) {
                return false;
            }

            if (ShopCatalog::kind($product->code) === ShopCatalog::PREFIX_TRIAL
                && ($petHistories[$animal->id] ?? null) === ShopCatalog::HISTORY_PREMIUM) {
                return false;
            }

            if ($product->species === 'Dog' && $product->breed_size_category
                && filled($animal->breed_size_category)
                && $animal->breed_size_category !== $product->breed_size_category) {
                return false;
            }

            return $product->fitsLifeStage($animal->age);
        });

        // Price for one pet (trial offer) when the customer has only one compatible pet
        $pricing = ProductPricing::for($product, $compatiblePets->count() === 1 ? $compatiblePets->first() : null);

        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->localizedName($lang),
            'description' => $product->localizedDescription($lang),
            'image_url' => $product->image_url,

            'species' => $product->species,
            'breed_size_category' => $product->breed_size_category,
            'life_stages' => $product->lifeStagesList(),
            'life_stages_label' => $product->lifeStagesLabel($lang),
            'age_options' => collect($this->allowedAges($product))->map(fn ($stage) => [
                'value' => $stage,
                'label' => Product::lifeStageLabel($stage, $lang),
            ])->values(),

            'product_type' => $product->product_type,
            'is_premium' => $product->product_type === 'premium',
            'is_trial' => (bool) $product->is_trial,

            // PREMIUM-... : recipes and days chosen by our team after the purchase
            'requires_consultation' => PremiumPack::isPremiumProduct($product),
            'consultation_message' => PremiumPack::isPremiumProduct($product) ? PremiumPack::productMessage($lang) : null,

            'price' => (float) $product->price,
            'current_price' => $pricing['price'],
            'has_promo' => $pricing['price'] < (float) $product->price,
            'discount_percent' => (float) $product->price > 0
                ? (int) round((1 - $pricing['price'] / (float) $product->price) * 100)
                : 0,
            'price_source' => $pricing['source'],
            'promo_ends_at' => $pricing['source'] === 'promo' ? $product->promo_ends_at?->toIso8601String() : null,
            'currency' => $product->currency,
            'price_per_recipe' => $product->total_recipes > 0 ? round($pricing['price'] / $product->total_recipes, 3) : 0,

            'duration_label' => $product->durationLabel($lang),
            'total_recipes' => $product->total_recipes,
            'rules' => [
                'min_deliveries' => $product->min_deliveries,
                'max_deliveries' => $product->max_deliveries,
                'min_recipes_per_delivery' => $product->min_recipes_per_delivery,
                'max_recipes_per_delivery' => $product->max_recipes_per_delivery,
            ],
            'recipes' => $product->recipes->map(fn ($recipe) => [
                'id' => $recipe->id,
                'name' => $lang === 'ar' && filled($recipe->name_ar ?? null) ? $recipe->name_ar : (string) $recipe->name,
                'is_premium' => (bool) ($recipe->is_premium ?? false),
            ])->values(),

            'compatible_pet_ids' => $compatiblePets->pluck('id')->values(),
        ];
    }

    private function formatPet(Animal $animal, string $lang): array
    {
        return [
            'id' => $animal->id,
            'name' => $animal->name,
            'species' => $animal->species,
            'age' => $animal->age,
            'age_label' => in_array($animal->age, Product::LIFE_STAGES, true)
                ? Product::lifeStageLabel($animal->age, $lang)
                : $animal->age,
            'breed_size_category' => $animal->breed_size_category,
            'photo_url' => $animal->photo_url ?? null,
            // Loyalty points of the pet (checkout: "use my points")
            'loyalty' => $this->petLoyalty($animal),
        ];
    }

    /** Points of a pet (null when the loyalty tables do not exist yet). */
    private function petLoyalty(Animal $animal): ?array
    {
        try {
            $preview = app(PetLoyaltyService::class)->preview($animal->id);

            return [
                'balance' => $preview['balance'],
                'available' => $preview['available'],
                'usable_points' => $preview['usable_points'],
                'discount' => $preview['discount'],
                'missing_points' => $preview['missing_points'],
                'expires_on' => $preview['expires_on'],
                'level' => $preview['level'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** Rules of the program shown in the app. */
    private function loyaltyProgram(): ?array
    {
        try {
            $settings = LoyaltySetting::current();

            return [
                'enabled' => $settings->is_enabled,
                'point_value' => (float) $settings->point_value,
                'currency' => $settings->currency,
                'min_points' => $settings->min_points_to_redeem,
                'max_points' => $settings->max_points_per_purchase,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** "My Cat", then "My Cat 2", "My Cat 3"... for the other new pets of the order */
    private function defaultPetName(string $species, string $lang, int $index = 0): string
    {
        $name = $lang === 'ar'
            ? ($species === 'Dog' ? 'كلبي' : 'قطتي')
            : ($species === 'Dog' ? 'My Dog' : 'My Cat');

        return $index > 0 ? $name . ' ' . ($index + 1) : $name;
    }

    private function paymentLabel(string $method, string $lang): string
    {
        $labels = [
            'knet' => ['en' => 'KNET', 'ar' => 'كي نت'],
            'card' => ['en' => 'Visa / Mastercard', 'ar' => 'فيزا / ماستركارد'],
            'bank_transfer' => ['en' => 'Bank transfer', 'ar' => 'تحويل بنكي'],
            'cash' => ['en' => 'Cash on delivery', 'ar' => 'الدفع عند الاستلام'],
        ];

        return $labels[$method][$lang] ?? $method;
    }

    private function lang(Request $request): string
    {
        return $request->input('lang') === 'ar' ? 'ar' : 'en';
    }

    private function error(string $message, int $status, array $errors = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'errors' => $errors ?: null,
        ], fn ($value) => $value !== null), $status);
    }
}

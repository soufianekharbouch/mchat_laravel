<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDelivery;
use App\Services\PurchaseScheduleService;
use App\Support\ProductPricing;
use App\Support\PremiumPack;
use App\Support\ShopCatalog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Mobile app: packs catalog, purchases ("my subscriptions")
 * and distribution of the recipes into deliveries.
 *
 * All routes are POST with the access_token (Bearer or body),
 * like the other mobile controllers.
 */
class MobilePurchaseController extends Controller
{
    /**
     * Payment methods the customer can choose in the app.
     * (online payment KNET / card: to add with the payment gateway)
     */
    public const APP_PAYMENT_METHODS = ['bank_transfer', 'cash'];

    /**
     * Online card payment (shop checkout): KNET + Visa / Mastercard.
     * No gateway yet (services.payment.gateway empty): /purchases/{id}/pay
     * and the shop checkout accept any card and mark the purchase paid.
     */
    public const CARD_PAYMENT_METHODS = ['knet', 'card'];

    public function __construct(private PurchaseScheduleService $schedule)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | CATALOG: products available for each pet
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/products            { access_token, lang, animal_id? }
    |
    */

    public function products(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated();
        }

        $lang = $this->language($request, $customer);

        $animals = $this->customerAnimals($customer)
            ->when($request->filled('animal_id'), fn ($animals) => $animals->where('id', (int) $request->animal_id))
            ->values();

        /*
         * Only TP- / REG- / PREMIUM- products. Each pet gets its own list:
         * species, size, age, and what THIS pet already bought
         * (premium: PREMIUM-, REG- / taste pack: REG-, PREMIUM-, TP-).
         */
        $products = ShopCatalog::onlyShopCodes(Product::query()->active())
            ->with('recipes')
            ->ordered()
            ->get();

        $purchaseCounts = $this->customerPurchasesQuery($customer)
            ->whereIn('status', Purchase::COUNTED_STATUSES)
            ->selectRaw('product_id, COUNT(*) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        return response()->json([
            'success' => true,
            'animals' => $animals->map(function (Animal $animal) use ($products, $lang, $purchaseCounts, $customer) {
                // Running trial of this pet (null when none): gives the trial offer prices
                $trial = ProductPricing::runningTrialFor($animal->id);

                // What this pet already bought: premium | trial | null
                $history = ShopCatalog::historyFor($customer, $animal->id);

                return [
                    'id' => $animal->id,
                    'name' => $animal->name,
                    'species' => $animal->species,
                    'breed_size_category' => $animal->breed_size_category,
                    'age' => $animal->age,
                    'age_label' => Product::lifeStageLabel(
                        in_array($animal->age, Product::LIFE_STAGES, true) ? $animal->age : null,
                        $lang
                    ),
                    'photo_url' => $animal->photo_url ?? null,

                    'plan_history' => $history,
                    'is_premium_pet' => $history === ShopCatalog::HISTORY_PREMIUM,

                    'trial' => $trial ? [
                        'purchase_id' => $trial->id,
                        'product_name' => $trial->localizedProductName($lang),
                        'ends_on' => $trial->ends_on?->format('Y-m-d'),
                    ] : null,

                    'products' => ShopCatalog::arrange($products, $history)
                        ->filter(fn (Product $product) => $this->productFitsAnimal($product, $animal))
                        ->map(fn (Product $product) => $this->formatProduct(
                            $product,
                            $lang,
                            (int) ($purchaseCounts[$product->id] ?? 0),
                            $animal
                        ))
                        // Order of the customer (PREMIUM- / REG- / TP-), then trial offers first
                        ->values()
                        ->map(fn (array $product, int $index) => $product + ['_index' => $index])
                        ->sortBy([
                            fn ($a, $b) => ShopCatalog::rank(new Product(['code' => $a['code']]), $history)
                                <=> ShopCatalog::rank(new Product(['code' => $b['code']]), $history),
                            fn ($a, $b) => ($a['trial_offer']['active'] ? 0 : 1) <=> ($b['trial_offer']['active'] ? 0 : 1),
                            fn ($a, $b) => $a['_index'] <=> $b['_index'],
                        ])
                        ->map(function (array $product) {
                            unset($product['_index']);

                            return $product;
                        })
                        ->values(),
                ];
            })->values(),
            'payment_methods' => $this->paymentMethods($lang),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | MY PURCHASES (shown as subscriptions in the app)
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/purchases           { access_token, lang, animal_id?, status? }
    |
    */

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated();
        }

        $lang = $this->language($request, $customer);

        $purchases = $this->customerPurchasesQuery($customer)
            ->when($request->filled('animal_id'), fn ($q) => $q->where('animal_id', (int) $request->animal_id))
            ->when($request->filled('status') && array_key_exists($request->status, Purchase::STATUSES),
                fn ($q) => $q->where('status', $request->status))
            ->with(['product', 'animal', 'deliveries.items', 'deliveries.plannedOrder', 'deliveries.customerAddress'])
            ->orderByRaw("FIELD(status, 'active', 'paid', 'pending', 'expired', 'completed', 'cancelled', 'refunded')")
            ->orderByDesc('purchased_at')
            ->get();

        foreach ($purchases as $purchase) {
            $this->schedule->syncDeliveryStatuses($purchase);
        }

        return response()->json([
            'success' => true,
            'purchases' => $purchases->map(fn (Purchase $purchase) => $this->formatPurchase($purchase, $lang))->values(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ONE PURCHASE + everything needed to plan the deliveries
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/purchases/{purchase}   { access_token, lang }
    |
    */

    public function show(Request $request, Purchase $purchase): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated();
        }

        if (!$this->ownsPurchase($customer, $purchase)) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'purchase' => $this->formatPurchaseDetail($purchase, $customer, $this->language($request, $customer)),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | BUY A PRODUCT
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/purchases/create
    | { access_token, lang, product_id, animal_id, payment_method, customer_note? }
    |
    | The purchase is created "pending". It becomes "paid" when the payment
    | is confirmed (back-office for transfer / cash, automatically later
    | with the online payment gateway).
    |
    */

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated();
        }

        $lang = $this->language($request, $customer);

        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'integer'],
            'animal_id' => ['required', 'integer'],
            'payment_method' => ['required', Rule::in(array_merge(self::APP_PAYMENT_METHODS, self::CARD_PAYMENT_METHODS))],
            'customer_note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->first(), $validator->errors()->toArray());
        }

        $product = Product::query()->active()->find($request->product_id);
        $animal = $this->customerAnimals($customer)->firstWhere('id', (int) $request->animal_id);

        // Only TP- / REG- / PREMIUM- are sold in the app (not the products made for one customer)
        if (!$product || !ShopCatalog::isShopCode($product->code)) {
            return $this->validationError($lang === 'ar' ? 'هذا المنتج غير متوفر.' : 'This product is not available.');
        }

        if (!$animal) {
            return $this->validationError($lang === 'ar' ? 'الحيوان غير موجود.' : 'Pet not found.');
        }

        if (!$this->productFitsAnimal($product, $animal)) {
            return $this->validationError(
                $lang === 'ar' ? 'هذا المنتج غير مناسب لهذا الحيوان.' : 'This product is not suitable for this pet.'
            );
        }

        /*
         * Purchase limit (e.g. trial pack once per customer).
         */
        if ($product->max_purchases_per_customer) {
            $alreadyBought = $this->customerPurchasesQuery($customer)
                ->where('product_id', $product->id)
                ->whereIn('status', Purchase::COUNTED_STATUSES)
                ->count();

            if ($alreadyBought >= $product->max_purchases_per_customer) {
                return $this->validationError(
                    $lang === 'ar' ? 'لقد وصلت إلى الحد الأقصى لشراء هذا المنتج.' : 'You have reached the purchase limit for this product.'
                );
            }
        }

        /*
         * Same product for the same pet still waiting for payment: no duplicate.
         * (Shop checkout with several packs for the same pet: allowed.)
         */
        $pending = $request->boolean('_shop_multi') ? null : $this->customerPurchasesQuery($customer)
            ->where('product_id', $product->id)
            ->where('animal_id', $animal->id)
            ->where('status', 'pending')
            ->first();

        if ($pending) {
            return response()->json([
                'success' => true,
                'already_pending' => true,
                'message' => $lang === 'ar'
                    ? 'لديك طلب شراء لهذا المنتج بانتظار الدفع.'
                    : 'You already have a purchase of this product waiting for payment.',
                'purchase' => $this->formatPurchaseDetail($pending, $customer, $lang),
            ]);
        }

        try {
            $purchase = DB::transaction(function () use ($customer, $product, $animal, $request) {
                $subscription = $customer->subscription;

                /*
                 * Price for this pet: promo, or trial offer while its trial is running.
                 * Locked to avoid using the same trial offer twice.
                 */
                Purchase::where('animal_id', $animal->id)->where('is_trial', true)->lockForUpdate()->get(['id']);

                $pricing = ProductPricing::for($product, $animal);

                $purchase = new Purchase();
                $purchase->fillFromProduct($product);

                $purchase->fill([
                    'customer_account_id' => $customer->id,
                    'subscription_id' => $customer->subscription_id,
                    'customer_name' => $subscription
                        ? trim($subscription->subscriber_first_name . ' ' . $subscription->subscriber_last_name)
                        : null,
                    'customer_phone' => $customer->phone,

                    'animal_id' => $animal->id,
                    'animal_name' => $animal->name,

                    'unit_price' => $pricing['price'],
                    'discount_amount' => 0,
                    'total_amount' => $pricing['price'],
                    'price_source' => $pricing['source'],
                    'trial_purchase_id' => $pricing['trial_purchase']?->id,

                    'source' => 'app',
                    'payment_method' => $request->payment_method,

                    'purchased_at' => now(),
                    'status' => 'pending',
                    'status_changed_at' => now(),

                    'customer_note' => $request->customer_note,
                ]);

                $purchase->save();

                $purchase->recordStatusHistory(null, 'pending', 'Purchase created in the mobile app.', 'customer');

                return $purchase;
            });
        } catch (Throwable $exception) {
            Log::error('Mobile purchase error', [
                'customer_account_id' => $customer->id,
                'product_id' => $product->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $lang === 'ar' ? 'تعذر إنشاء الطلب.' : 'Unable to create the purchase.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => $lang === 'ar'
                ? 'تم تسجيل طلبك. سيتم تفعيله بعد تأكيد الدفع.'
                : 'Your purchase has been registered. It will be activated once the payment is confirmed.',
            'purchase' => $this->formatPurchaseDetail($purchase, $customer, $lang),
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | CARD PAYMENT (KNET / VISA / MASTERCARD) - SIMULATION
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/purchases/{purchase}/pay
    | { access_token, lang, payment_method: knet|card, card_last4? }
    |
    | The card details never leave the phone (only the last 4 digits).
    | While there is no payment gateway (services.payment.gateway empty)
    | every payment succeeds: the purchase becomes "paid".
    | Later: this method will start the real KNET / card payment instead.
    |
    */

    public function pay(Request $request, Purchase $purchase): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated();
        }

        if (!$this->ownsPurchase($customer, $purchase)) {
            return $this->notFound();
        }

        $lang = $this->language($request, $customer);

        $validator = Validator::make($request->all(), [
            'payment_method' => ['required', Rule::in(self::CARD_PAYMENT_METHODS)],
            'card_last4' => ['nullable', 'digits:4'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->first(), $validator->errors()->toArray());
        }

        if (!self::simulationEnabled()) {
            return response()->json([
                'success' => false,
                'message' => $lang === 'ar'
                    ? 'الدفع الإلكتروني غير متاح حالياً.'
                    : 'Online payment is not available right now.',
            ], 503);
        }

        if ($purchase->status !== 'pending') {
            return response()->json([
                'success' => true,
                'already_paid' => true,
                'message' => $lang === 'ar' ? 'تم تأكيد الدفع بالفعل.' : 'The payment is already confirmed.',
                'purchase' => $this->formatPurchaseDetail($purchase->fresh(), $customer, $lang),
            ]);
        }

        try {
            self::markCardPaid($purchase, $request->input('payment_method'), $request->input('card_last4'));
        } catch (Throwable $exception) {
            Log::error('Simulated payment error', [
                'purchase_id' => $purchase->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $lang === 'ar' ? 'تعذر إتمام الدفع. حاول مرة أخرى.' : 'Payment failed. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'simulated' => true,
            'message' => $lang === 'ar' ? 'تم الدفع بنجاح.' : 'Payment successful.',
            'purchase' => $this->formatPurchaseDetail($purchase->fresh(), $customer, $lang),
        ]);
    }

    /**
     * Card payment simulation: ON as long as no payment gateway is connected
     * (services.payment.gateway empty). Once the KNET / card gateway is set,
     * the real payment is used and the simulation stops automatically.
     */
    public static function simulationEnabled(): bool
    {
        return blank(config('services.payment.gateway'));
    }

    /**
     * Simulated card payment: pending -> paid (deliveries can be planned
     * right away). Used by /purchases/{id}/pay and by the shop checkout.
     */
    public static function markCardPaid(Purchase $purchase, string $method, ?string $last4 = null): void
    {
        DB::transaction(function () use ($purchase, $method, $last4) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($purchase->status !== 'pending') {
                return;
            }

            $purchase->payment_method = $method;
            $purchase->payment_reference = 'SIM-' . strtoupper($method) . '-' . now()->format('ymdHis') . '-' . random_int(1000, 9999);
            $purchase->save();

            $purchase->changeStatus(
                'paid',
                'Online payment SIMULATION (' . strtoupper($method) . ($last4 ? ' **** ' . $last4 : '') . '). No real charge.',
                'customer'
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | BANK TRANSFER RECEIPT
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/purchases/{purchase}/payment-proof   (multipart)
    | { access_token, proof: file, payment_reference? }
    |
    */

    public function uploadPaymentProof(Request $request, Purchase $purchase): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated();
        }

        if (!$this->ownsPurchase($customer, $purchase)) {
            return $this->notFound();
        }

        $lang = $this->language($request, $customer);

        if ($purchase->status !== 'pending') {
            return $this->validationError($lang === 'ar' ? 'تم تأكيد الدفع بالفعل.' : 'The payment is already confirmed.');
        }

        $validator = Validator::make($request->all(), [
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'payment_reference' => ['nullable', 'string', 'max:191'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->first(), $validator->errors()->toArray());
        }

        $oldProof = $purchase->payment_proof;

        $purchase->payment_proof = $request->file('proof')->store('purchase_proofs', 'public');
        $purchase->payment_method = 'bank_transfer';

        if ($request->filled('payment_reference')) {
            $purchase->payment_reference = $request->payment_reference;
        }

        $purchase->save();

        if ($oldProof && $oldProof !== $purchase->payment_proof) {
            Storage::disk('public')->delete($oldProof);
        }

        $purchase->recordStatusHistory('pending', 'pending', 'Bank transfer receipt sent by the customer.', 'customer');

        return response()->json([
            'success' => true,
            'message' => $lang === 'ar'
                ? 'تم إرسال إيصال التحويل. سنقوم بالتحقق منه قريباً.'
                : 'Your transfer receipt has been sent. We will check it shortly.',
            'purchase' => $this->formatPurchaseDetail($purchase, $customer, $lang),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CANCEL A PURCHASE NOT PAID YET
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/purchases/{purchase}/cancel   { access_token, reason? }
    |
    */

    public function cancel(Request $request, Purchase $purchase): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated();
        }

        if (!$this->ownsPurchase($customer, $purchase)) {
            return $this->notFound();
        }

        $lang = $this->language($request, $customer);

        if ($purchase->status !== 'pending') {
            return $this->validationError(
                $lang === 'ar'
                    ? 'لا يمكن إلغاء هذا الطلب من التطبيق. يرجى التواصل مع خدمة العملاء.'
                    : 'This purchase cannot be cancelled from the app. Please contact customer support.'
            );
        }

        $reason = trim((string) $request->input('reason', ''));

        $purchase->changeStatus(
            'cancelled',
            'Cancelled by the customer in the app.' . ($reason !== '' ? ' Reason: ' . $reason : ''),
            'customer'
        );

        return response()->json([
            'success' => true,
            'purchase' => $this->formatPurchaseDetail($purchase, $customer, $lang),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DISTRIBUTE THE RECIPES (all deliveries at once)
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/purchases/{purchase}/schedule
    | {
    |   access_token, lang, animal_id?,
    |   deliveries: [
    |     { id?, date: "2026-10-20", delivery_slot?, address_id?,
    |       items: [ { recipe_id: 3, quantity: 6 }, { recipe_id: 7, quantity: 4 } ] }
    |   ]
    | }
    |
    | Editable deliveries missing from the list are removed.
    | Deliveries already prepared / past the cutoff are kept unchanged.
    |
    */

    public function schedule(Request $request, Purchase $purchase): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated();
        }

        if (!$this->ownsPurchase($customer, $purchase)) {
            return $this->notFound();
        }

        $lang = $this->language($request, $customer);

        $validator = Validator::make($request->all(), [
            'animal_id' => ['nullable', 'integer'],
            'deliveries' => ['present', 'array'],
            'deliveries.*.id' => ['nullable', 'integer'],
            'deliveries.*.date' => ['required', 'date_format:Y-m-d'],
            'deliveries.*.delivery_slot' => ['nullable', 'string'],
            'deliveries.*.address_id' => ['nullable', 'integer'],
            'deliveries.*.items' => ['required', 'array', 'min:1'],
            'deliveries.*.items.*.recipe_id' => ['required', 'integer'],
            'deliveries.*.items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->first(), $validator->errors()->toArray());
        }

        // PREMIUM-... untreated: recipes and days chosen after our call
        if (PremiumPack::isPending($purchase)) {
            return $this->validationError(PremiumPack::blockedMessage($lang));
        }

        // Older pack of the same pet with meals not planned yet: that one first
        if ($previous = $this->schedule->previousToPlan($purchase)) {
            return response()->json([
                'success' => false,
                'previous_purchase_id' => $previous->id,
                'message' => $lang === 'ar'
                    ? 'وزّع أولاً الوجبات المتبقية من باقتك السابقة.'
                    : 'Please plan the remaining meals of your previous pack first.',
                'errors' => ['previous' => ['required']],
            ], 422);
        }

        // No delivery address yet: the app asks for it first (planning screen)
        if (!$customer->addresses()->exists()) {
            return response()->json([
                'success' => false,
                'requires_address' => true,
                'message' => $lang === 'ar'
                    ? 'أضف عنوان التوصيل أولاً لتنظيم مواعيد التوصيل.'
                    : 'Please add your delivery address first to plan your deliveries.',
                'errors' => ['address' => ['required']],
            ], 422);
        }

        try {
            $purchase = $this->schedule->saveSchedule(
                $purchase,
                $request->input('deliveries', []),
                $customer,
                $request->filled('animal_id') ? (int) $request->animal_id : null
            );
        } catch (ValidationException $exception) {
            return $this->validationError(
                collect($exception->errors())->flatten()->first(),
                $exception->errors()
            );
        } catch (Throwable $exception) {
            Log::error('Mobile purchase schedule error', [
                'purchase_id' => $purchase->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $lang === 'ar' ? 'تعذر حفظ مواعيد التوصيل.' : 'Unable to save the deliveries.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => $lang === 'ar' ? 'تم حفظ مواعيد التوصيل.' : 'Your deliveries have been saved.',
            'purchase' => $this->formatPurchaseDetail($purchase, $customer, $lang),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | QUICK PLANNING (one tap)
    |--------------------------------------------------------------------------
    |
    | POST /purchases/{purchase}/quick-plan { date: Y-m-d, delivery_slot? }
    |
    | The customer only chooses the day of the FIRST delivery: the recipes and
    | the next deliveries are planned automatically (same rules as Shopify
    | orders: max deliveries of the pack, next one when the meals are eaten).
    | Taste packs (1 delivery): the whole pack on that day.
    | Everything can still be changed in the planning screen.
    |
    */

    public function quickPlan(Request $request, Purchase $purchase): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated();
        }

        if (!$this->ownsPurchase($customer, $purchase)) {
            return $this->notFound();
        }

        $lang = $this->language($request, $customer);

        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'delivery_slot' => ['nullable', Rule::in(CustomerAddress::DELIVERY_SLOTS)],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->first(), $validator->errors()->toArray());
        }

        if (!in_array($purchase->status, ['paid', 'active'], true)) {
            return $this->validationError($lang === 'ar' ? 'يجب إتمام الدفع أولاً.' : 'The payment must be completed first.');
        }

        // PREMIUM-... untreated: recipes and days chosen after our call
        if (PremiumPack::isPending($purchase)) {
            return $this->validationError(PremiumPack::blockedMessage($lang));
        }

        // Older pack of the same pet with meals not planned yet: that one first
        if ($previous = $this->schedule->previousToPlan($purchase)) {
            return response()->json([
                'success' => false,
                'previous_purchase_id' => $previous->id,
                'message' => $lang === 'ar'
                    ? 'وزّع أولاً الوجبات المتبقية من باقتك السابقة.'
                    : 'Please plan the remaining meals of your previous pack first.',
                'errors' => ['previous' => ['required']],
            ], 422);
        }

        // No delivery address yet: the app asks for it first (planning screen)
        if (!$customer->addresses()->exists()) {
            return response()->json([
                'success' => false,
                'requires_address' => true,
                'message' => $lang === 'ar'
                    ? 'أضف عنوان التوصيل أولاً لتنظيم مواعيد التوصيل.'
                    : 'Please add your delivery address first to plan your deliveries.',
                'errors' => ['address' => ['required']],
            ], 422);
        }

        $animal = $purchase->animal;

        if (!$animal) {
            return $this->validationError($lang === 'ar' ? 'يرجى اختيار الحيوان لهذه الباقة.' : 'Choose the pet of this pack first.');
        }

        $date = Carbon::createFromFormat('Y-m-d', $request->input('date'))->startOfDay();
        $earliest = $this->schedule->earliestDateFor($purchase);

        if ($date->lt($earliest) || in_array($date->dayOfWeek, PurchaseScheduleService::REST_DAYS, true)) {
            return $this->validationError($lang === 'ar'
                ? 'هذا اليوم غير متاح. أول يوم متاح: ' . $earliest->format('Y-m-d')
                : 'This day is not available. First available day: ' . $earliest->format('Y-m-d'));
        }

        // Address: the one of the deliveries already planned, otherwise the default one
        $addressId = $purchase->deliveries()->where('status', '!=', 'cancelled')->value('customer_address_id')
            ?? $customer->addresses()->orderByDesc('is_default')->value('id');

        $result = app(\App\Services\AutoDeliveryPlanner::class)->plan(
            $purchase,
            $customer,
            $animal,
            now(),
            $addressId,
            'customer',
            $date,
            $request->input('delivery_slot')
        );

        if (!$result['ok']) {
            return $this->validationError($lang === 'ar'
                ? 'تعذر جدولة التوصيل في هذا اليوم. جرّب يوماً آخر أو استخدم صفحة الجدولة.'
                : 'Unable to plan the delivery on this day. Try another day or use the planning screen.');
        }

        return response()->json([
            'success' => true,
            'message' => $lang === 'ar' ? 'تم تحديد موعد التوصيل.' : 'Your delivery is planned.',
            'purchase' => $this->formatPurchaseDetail($purchase->fresh(), $customer, $lang),
        ]);
    }

    /** Days the customer can choose for the first delivery (next 14 open days). */
    public static function availableDeliveryDays(PurchaseScheduleService $schedule, int $count = 14, ?Carbon $from = null): array
    {
        $days = [];
        $date = ($from ?? $schedule->earliestDeliveryDate())->copy();

        while (count($days) < $count) {
            if (!in_array($date->dayOfWeek, PurchaseScheduleService::REST_DAYS, true)) {
                $days[] = $date->format('Y-m-d');
            }

            $date->addDay();
        }

        return $days;
    }

    /*
    |--------------------------------------------------------------------------
    | SKIP ONE DELIVERY
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/purchases/{purchase}/deliveries/{delivery}/cancel
    | The recipes of the delivery go back to the remaining recipes.
    |
    */

    public function cancelDelivery(Request $request, Purchase $purchase, PurchaseDelivery $delivery): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated();
        }

        if (!$this->ownsPurchase($customer, $purchase) || (int) $delivery->purchase_id !== (int) $purchase->id) {
            return $this->notFound();
        }

        $lang = $this->language($request, $customer);

        try {
            $this->schedule->cancelDelivery($purchase, $delivery);
        } catch (ValidationException $exception) {
            return $this->validationError(
                $lang === 'ar' ? 'لم يعد بالإمكان تعديل هذا التوصيل.' : collect($exception->errors())->flatten()->first()
            );
        }

        return response()->json([
            'success' => true,
            'message' => $lang === 'ar' ? 'تم إلغاء موعد التوصيل.' : 'The delivery has been removed.',
            'purchase' => $this->formatPurchaseDetail($purchase->fresh(), $customer, $lang),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | FORMATTING
    |--------------------------------------------------------------------------
    */

    private function formatProduct(Product $product, string $lang, int $alreadyBought = 0, ?Animal $animal = null): array
    {
        $limitReached = $product->max_purchases_per_customer
            && $alreadyBought >= $product->max_purchases_per_customer;

        $pricing = ProductPricing::for($product, $animal);
        $trialOffer = $pricing['source'] === 'trial_offer';

        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->localizedName($lang),
            'name_en' => $product->name,
            'name_ar' => $product->name_ar,
            'description' => $product->localizedDescription($lang),
            'image_url' => $product->image_url,

            'species' => $product->species,
            'breed_size_category' => $product->breed_size_category,
            'life_stages' => $product->lifeStagesList(),
            'life_stages_label' => $product->lifeStagesLabel($lang),
            'product_type' => $product->product_type,
            'is_premium' => $product->product_type === 'premium',
            'is_trial' => (bool) $product->is_trial,

            'price' => (float) $product->price,
            // Price to pay for this pet (promo or trial offer included)
            'current_price' => $pricing['price'],
            'has_promo' => $trialOffer || $product->hasActivePromo(),
            'discount_percent' => (float) $product->price > 0
                ? (int) round((1 - $pricing['price'] / (float) $product->price) * 100)
                : 0,
            'promo_ends_at' => !$trialOffer && $product->hasActivePromo()
                ? $product->promo_ends_at?->toIso8601String()
                : null,
            'price_source' => $pricing['source'],

            // Special price while the pet's trial is running
            'trial_offer' => [
                'active' => $trialOffer,
                'price' => $trialOffer ? $pricing['price'] : null,
                'ends_on' => $trialOffer ? $pricing['trial_ends_on'] : null,
                'trial_product_name' => $trialOffer ? $pricing['trial_purchase']->localizedProductName($lang) : null,
            ],
            'currency' => $product->currency,
            'price_per_recipe' => $product->total_recipes > 0
                ? round($pricing['price'] / $product->total_recipes, 3)
                : 0,

            'duration_value' => $product->duration_value,
            'duration_unit' => $product->duration_unit,
            'duration_label' => $product->durationLabel($lang),

            'total_recipes' => $product->total_recipes,
            'rules' => [
                'min_deliveries' => $product->min_deliveries,
                'max_deliveries' => $product->max_deliveries,
                'min_recipes_per_delivery' => $product->min_recipes_per_delivery,
                'max_recipes_per_delivery' => $product->max_recipes_per_delivery,
                'min_days_between_deliveries' => $product->min_days_between_deliveries,
            ],

            'recipes' => $product->recipes->map(fn ($recipe) => [
                'id' => $recipe->id,
                'name' => $this->recipeName($recipe, $lang),
                'code' => $recipe->code ?? null,
                'is_premium' => (bool) ($recipe->is_premium ?? false),
                'max_quantity' => $recipe->pivot->max_quantity !== null ? (int) $recipe->pivot->max_quantity : null,
                'is_default' => (bool) $recipe->pivot->is_default,
            ])->values(),

            // PREMIUM-... : recipes and days chosen by our team after the purchase
            'requires_consultation' => PremiumPack::isPremiumProduct($product),
            'consultation_message' => PremiumPack::isPremiumProduct($product) ? PremiumPack::productMessage($lang) : null,

            'purchases_count' => $alreadyBought,
            'max_purchases_per_customer' => $product->max_purchases_per_customer,
            'can_purchase' => !$limitReached,
        ];
    }

    /**
     * Purchase as a "subscription card".
     */
    private function formatPurchase(Purchase $purchase, string $lang): array
    {
        $purchase->loadMissing(['animal', 'product', 'deliveries.items', 'deliveries.plannedOrder', 'deliveries.customerAddress']);

        $active = $purchase->deliveries->where('status', '!=', 'cancelled');

        $scheduled = (int) $active->sum(fn ($d) => $d->items->sum('quantity'));
        $delivered = (int) $active->where('status', 'delivered')->sum(fn ($d) => $d->items->sum('quantity'));

        $next = $active
            ->filter(fn ($d) => $d->status !== 'delivered' && Carbon::parse($d->delivery_date)->gte(Carbon::today()))
            ->sortBy('delivery_date')
            ->first();

        // PREMIUM-... : untreated = nothing to plan, treated = custom recipes ready
        $premiumPending = PremiumPack::isPending($purchase);

        $daysLeft = $purchase->ends_on
            ? (int) Carbon::today()->diffInDays($purchase->ends_on, false)
            : null;

        return [
            'id' => $purchase->id,
            'reference' => $purchase->reference,

            'status' => $purchase->status,
            'status_label' => Purchase::statusLabel($purchase->status, $lang),
            'is_active' => in_array($purchase->status, ['paid', 'active'], true),

            'is_personalized' => PremiumPack::isPremiumPurchase($purchase),
            'needs_consultation' => $premiumPending,
            'custom_ready' => PremiumPack::isDone($purchase),
            'consultation_message' => $premiumPending ? PremiumPack::pendingMessage($lang) : null,

            'product' => [
                'id' => $purchase->product_id,
                'code' => $purchase->product_code,
                'name' => $purchase->localizedProductName($lang),
                'image_url' => $purchase->product?->image_url,
                'species' => $purchase->species,
                'product_type' => $purchase->product_type,
                'is_premium' => $purchase->product_type === 'premium',
                'is_trial' => (bool) $purchase->is_trial,
            ],

            'animal' => $purchase->animal_id ? [
                'id' => $purchase->animal_id,
                'name' => $purchase->animal?->name ?? $purchase->animal_name,
                'photo_url' => $purchase->animal?->photo_url ?? null,
            ] : null,

            'price' => [
                'unit_price' => (float) $purchase->unit_price,
                'discount_amount' => (float) $purchase->discount_amount,
                'total' => (float) $purchase->total_amount,
                'currency' => $purchase->currency,
                'source' => $purchase->price_source,
                'is_trial_offer' => $purchase->price_source === 'trial_offer',
            ],

            'payment' => [
                'method' => $purchase->payment_method,
                'method_label' => $purchase->payment_method_label,
                'reference' => $purchase->payment_reference,
                'proof_uploaded' => (bool) $purchase->payment_proof,
                'paid_at' => $purchase->paid_at?->toIso8601String(),
            ],

            'period' => [
                'purchased_at' => $purchase->purchased_at?->toIso8601String(),
                'starts_on' => $purchase->starts_on?->format('Y-m-d'),
                'ends_on' => $purchase->ends_on?->format('Y-m-d'),
                'duration_label' => $purchase->durationLabel($lang),
                'days_left' => $daysLeft,
            ],

            'usage' => [
                'total_recipes' => $purchase->total_recipes,
                'scheduled_recipes' => $scheduled,
                'delivered_recipes' => $delivered,
                'remaining_recipes' => max(0, $purchase->total_recipes - $scheduled),
                'deliveries_count' => $active->count(),
                'max_deliveries' => $purchase->max_deliveries,
            ],

            // Quick planning (banner of the app): days for the first delivery
            // (none while an older pack of the pet still has meals to plan)
            'quick_plan_days' => !$premiumPending && in_array($purchase->status, ['paid', 'active'], true) && $purchase->total_recipes - $scheduled > 0
                && !$this->schedule->previousToPlan($purchase)
                ? self::availableDeliveryDays($this->schedule, 14, $this->schedule->earliestDateFor($purchase))
                : [],

            'next_delivery' => $next ? $this->formatDelivery($next, $lang) : null,

            // Trial pack still running: special prices to offer on other packs
            'trial_offer' => $this->trialOfferSummary($purchase),

            'can_schedule' => !$premiumPending
                && in_array($purchase->status, ['paid', 'active'], true)
                && ($purchase->total_recipes - $scheduled > 0 || $active->contains(fn ($d) => $this->schedule->isEditable($d))),
            'can_upload_payment_proof' => $purchase->status === 'pending',
            'can_cancel' => $purchase->status === 'pending',
        ];
    }

    /**
     * Card + rules + deliveries + allowed recipes + addresses (planning screen).
     */
    private function formatPurchaseDetail(Purchase $purchase, CustomerAccount $customer, string $lang): array
    {
        $purchase->load(['animal', 'product', 'deliveries.items', 'deliveries.plannedOrder', 'deliveries.customerAddress']);
        $this->schedule->syncDeliveryStatuses($purchase);

        // After the previous packs of the pet (their meals eaten first)
        $earliest = $this->schedule->earliestDateFor($purchase);
        $previous = $this->schedule->previousToPlan($purchase);
        $startsAfter = $this->schedule->startAfterPreviousPacks($purchase);

        $latest = $purchase->ends_on
            ? Carbon::parse($purchase->ends_on)
            : $purchase->computeEndDate($earliest);

        return $this->formatPurchase($purchase, $lang) + [
            'rules' => [
                'total_recipes' => $purchase->total_recipes,
                'min_deliveries' => $purchase->min_deliveries,
                'max_deliveries' => $purchase->max_deliveries,
                'min_recipes_per_delivery' => $purchase->min_recipes_per_delivery,
                'max_recipes_per_delivery' => $purchase->max_recipes_per_delivery,
                'min_days_between_deliveries' => $purchase->min_days_between_deliveries,
                'edit_cutoff_hour' => PurchaseScheduleService::CUTOFF_HOUR,
                'rest_days' => PurchaseScheduleService::REST_DAYS, // 0 = Sunday
                'earliest_date' => $earliest->format('Y-m-d'),
                'latest_date' => $latest->format('Y-m-d'),
            ],

            'deliveries' => $purchase->deliveries
                ->where('status', '!=', 'cancelled')
                ->sortBy('delivery_date')
                ->map(fn ($delivery) => $this->formatDelivery($delivery, $lang))
                ->values(),

            'recipes' => $this->schedule->allowedRecipes($purchase)
                ->map(fn ($row) => [
                    'id' => $row['recipe']->id,
                    'name' => $this->recipeName($row['recipe'], $lang),
                    'code' => $row['recipe']->code ?? null,
                    'is_premium' => (bool) ($row['recipe']->is_premium ?? false),
                    'max_quantity' => $row['max_quantity'],
                    'is_default' => $row['is_default'],
                ])
                ->values(),

            'addresses' => CustomerAddress::query()
                ->with(['provinceRelation', 'zone'])
                ->where('customer_account_id', $customer->id)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
                ->map(fn ($address) => $address->toMobileArray($lang))
                ->values(),

            'delivery_slots' => CustomerAddress::DELIVERY_SLOTS,

            // Older pack of this pet to plan first / new pack starts after the previous ones
            'previous_to_plan' => $previous ? [
                'id' => $previous->id,
                'product_code' => $previous->product_code,
                'product_name' => $previous->localizedProductName($lang),
                'remaining_recipes' => $previous->remainingRecipesCount(),
                'total_recipes' => $previous->total_recipes,
            ] : null,
            'starts_after_previous' => $startsAfter && $startsAfter->gt($this->schedule->earliestDeliveryDate())
                ? $startsAfter->format('Y-m-d')
                : null,

            // No address yet: the planning screen asks for it first
            'requires_address' => !$customer->addresses()->exists(),

            'notes' => [
                'customer' => $purchase->customer_note,
            ],
        ];
    }

    private function formatDelivery(PurchaseDelivery $delivery, string $lang): array
    {
        $editable = $this->schedule->isEditable($delivery);
        $address = $delivery->customerAddress;

        return [
            'id' => $delivery->id,
            'number' => $delivery->delivery_number,
            'date' => Carbon::parse($delivery->delivery_date)->format('Y-m-d'),
            'delivery_slot' => $delivery->delivery_slot,
            'status' => $delivery->status,
            'status_label' => $this->deliveryStatusLabel($delivery->status, $lang),
            'recipes_count' => (int) $delivery->items->sum('quantity'),
            'items' => $delivery->items->map(fn ($item) => [
                'recipe_id' => $item->recipe_id,
                'name' => $item->recipe_name,
                'quantity' => $item->quantity,
            ])->values(),
            'address' => $address ? [
                'id' => $address->id,
                'label' => $address->label,
                'address' => $address->address,
            ] : null,
            'is_editable' => $editable,
            // Date passed and not delivered yet (shown in red in the calendar)
            'is_delayed' => in_array($delivery->status, ['scheduled', 'prepared'], true)
                && Carbon::parse($delivery->delivery_date)->lt(Carbon::today()),
            'edit_cutoff' => $this->schedule->cutoffFor($delivery)->toIso8601String(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Same authentication as the other mobile controllers
     * (+ expired token refused when an expiry date is stored).
     */
    private function customer(Request $request): ?CustomerAccount
    {
        $token = $request->bearerToken() ?? $request->input('access_token');

        if (!$token) {
            return null;
        }

        $customer = CustomerAccount::where('access_token_hash', hash('sha256', $token))->first();

        if (!$customer) {
            return null;
        }

        if ($customer->access_token_expires_at && Carbon::parse($customer->access_token_expires_at)->isPast()) {
            return null;
        }

        return $customer;
    }

    private function language(Request $request, CustomerAccount $customer): string
    {
        $settings = is_array($customer->settings) ? $customer->settings : [];
        $lang = $request->input('lang') ?? ($settings['language'] ?? 'en');

        return $lang === 'ar' ? 'ar' : 'en';
    }

    /**
     * Purchases of the customer: linked by account or by subscription
     * (purchases added in the back-office before the account existed).
     */
    private function customerPurchasesQuery(CustomerAccount $customer)
    {
        return Purchase::query()->where(function ($query) use ($customer) {
            $query->where('customer_account_id', $customer->id);

            if ($customer->subscription_id) {
                $query->orWhere('subscription_id', $customer->subscription_id);
            }
        });
    }

    private function ownsPurchase(CustomerAccount $customer, Purchase $purchase): bool
    {
        return (int) $purchase->customer_account_id === (int) $customer->id
            || ($customer->subscription_id && (int) $purchase->subscription_id === (int) $customer->subscription_id);
    }

    private function customerAnimals(CustomerAccount $customer)
    {
        if (!$customer->subscription_id) {
            return collect();
        }

        return Animal::where('subscription_id', $customer->subscription_id)->orderBy('id')->get();
    }

    /**
     * For a running trial: lowest "trial offer" price among the packs
     * that suit the pet (null when nothing to offer).
     */
    private function trialOfferSummary(Purchase $purchase): ?array
    {
        if (!$purchase->is_trial || !$purchase->animal) {
            return null;
        }

        $trial = ProductPricing::runningTrialFor($purchase->animal_id);

        if (!$trial || (int) $trial->id !== (int) $purchase->id) {
            return null;
        }

        $offers = Product::query()
            ->active()
            ->where('is_trial', false)
            ->whereNotNull('trial_offer_price')
            ->where('species', $purchase->animal->species)
            ->get()
            ->filter(fn (Product $product) => $product->hasTrialOffer()
                && $this->productFitsAnimal($product, $purchase->animal));

        if ($offers->isEmpty()) {
            return null;
        }

        return [
            'available' => true,
            'products_count' => $offers->count(),
            'from_price' => (float) $offers->min(fn (Product $product) => (float) $product->trial_offer_price),
            'currency' => $offers->first()->currency,
            'ends_on' => $purchase->ends_on?->format('Y-m-d'),
        ];
    }

    private function productFitsAnimal(Product $product, Animal $animal): bool
    {
        if ($product->species !== ($animal->species ?? null)) {
            return false;
        }

        if ($product->species === 'Dog' && $product->breed_size_category && filled($animal->breed_size_category ?? null)
            && $product->breed_size_category !== $animal->breed_size_category) {
            return false;
        }

        // Age of the pet (Kitten / Puppy / Adult / Senior)
        return $product->fitsLifeStage($animal->age ?? null);
    }

    private function recipeName($recipe, string $lang): string
    {
        return $lang === 'ar' && filled($recipe->name_ar ?? null) ? $recipe->name_ar : (string) $recipe->name;
    }

    private function paymentMethods(string $lang): array
    {
        $labels = [
            'knet' => ['en' => 'KNET', 'ar' => 'كي نت'],
            'card' => ['en' => 'Visa / Mastercard', 'ar' => 'فيزا / ماستركارد'],
            'bank_transfer' => ['en' => 'Bank transfer', 'ar' => 'تحويل بنكي'],
            'cash' => ['en' => 'Cash on delivery', 'ar' => 'الدفع عند الاستلام'],
        ];

        // Plans page: card payment only (KNET, Visa / Mastercard)
        return collect(self::CARD_PAYMENT_METHODS)
            ->map(fn ($method) => ['value' => $method, 'label' => $labels[$method][$lang] ?? $method])
            ->values()
            ->all();
    }

    private function deliveryStatusLabel(string $status, string $lang): string
    {
        $labels = [
            'scheduled' => ['en' => 'Scheduled', 'ar' => 'مجدول'],
            'prepared' => ['en' => 'Being prepared', 'ar' => 'قيد التحضير'],
            'delivered' => ['en' => 'Delivered', 'ar' => 'تم التوصيل'],
            'cancelled' => ['en' => 'Cancelled', 'ar' => 'ملغى'],
        ];

        return $labels[$status][$lang] ?? $status;
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Purchase not found.'], 404);
    }

    private function validationError(string $message, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors ?: ['general' => [$message]],
        ], 422);
    }
}

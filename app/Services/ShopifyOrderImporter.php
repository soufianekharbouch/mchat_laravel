<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Models\Province;
use App\Models\Purchase;
use App\Models\ShopifyOrder;
use App\Models\Subscription;
use App\Models\Zone;
use App\Support\KuwaitPhone;
use App\Support\SubscriptionCode;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shopify order -> customer + pet + paid purchase(s) + planned deliveries.
 *
 *  1. Paid order only: financial_status paid / partially_paid, and
 *     "authorized" (card authorized, captured later in Shopify) when
 *     SHOPIFY_ACCEPT_AUTHORIZED=true (default).
 *  2. Phone: Shopify "email" field (or phone fields), with or without +965.
 *  3. Products: line item variant_id / product_id = products.shopify_*_id.
 *     None linked -> NOTHING is done (status failed + reason).
 *  4. Customer: same phone (8 local digits) = existing customer,
 *     otherwise a new subscription + app account.
 *  5. Address: Shopify governorate + zone found in the address text.
 *  6. Pet: the customer's only pet of that species, otherwise a new one
 *     ("My Cat" / "My Dog", species + age of the product).
 *  7. Purchase paid (source shopify) + automatic planning (J+2).
 *
 * Every problem is written in shopify_orders.issues ("Auto subscription").
 */
class ShopifyOrderImporter
{
    private array $issues = [];

    public function __construct(private AutoDeliveryPlanner $planner)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | ENTRY POINT
    |--------------------------------------------------------------------------
    */

    public function process(ShopifyOrder $order, bool $manual = false): ShopifyOrder
    {
        $lock = Cache::lock('shopify-order-' . $order->shopify_order_id, 120);

        if (!$lock->get()) {
            return $order; // already being processed (orders/create + orders/paid together)
        }

        try {
            return $this->run($order->fresh(), $manual);
        } catch (Throwable $exception) {
            report($exception);

            $order->forceFill([
                'status' => $order->purchases()->exists() ? 'needs_review' : 'failed',
                'issues' => array_merge($order->issues ?? [], [[
                    'level' => 'error',
                    'code' => 'exception',
                    'message' => 'Unexpected error: ' . Str::limit($exception->getMessage(), 300),
                ]]),
                'processed_at' => now(),
            ])->save();

            return $order;
        } finally {
            $lock->release();
        }
    }

    private function run(ShopifyOrder $order, bool $manual): ShopifyOrder
    {
        $this->issues = [];
        $payload = $order->payloadArray();

        $order->attempts = (int) $order->attempts + 1;
        $this->fillSummary($order, $payload);

        /*
         * Already done: never twice (a webhook can be sent several times).
         */
        // Ignored by the staff: never processed again automatically (webhooks)
        if (!$manual && $order->status === 'ignored') {
            $order->save();

            return $order;
        }

        if (!$manual && in_array($order->status, ['processed', 'needs_review'], true) && $order->processed_at) {
            $order->save();

            return $order;
        }

        /*
         * Cancelled / not paid yet.
         */
        if (!empty($payload['cancelled_at'])) {
            return $this->finish($order, 'ignored', 'info', 'cancelled', 'Order cancelled on Shopify: nothing created.');
        }

        if (!in_array($order->financial_status, self::acceptedFinancialStatuses(), true)) {
            return $this->finish(
                $order,
                'pending',
                'warning',
                'not_paid',
                "Payment not completed on Shopify yet (status: " . ($order->financial_status ?: 'unknown')
                . "). It will be processed automatically when the order is paid."
            );
        }

        /*
         * Phone (required to find / create the customer).
         */
        if (!$order->phone) {
            return $this->finish(
                $order,
                'failed',
                'error',
                'phone_missing',
                'Nothing done: no valid Kuwaiti phone number found (Shopify "email" field: "'
                . ($order->phone_raw ?: 'empty') . '").'
            );
        }

        /*
         * Products.
         */
        $lines = $this->matchLines($payload);
        $order->line_items = $lines->map(fn ($line) => collect($line)->except('product')->all())->values()->all();

        $matched = $lines->filter(fn ($line) => $line['product'] !== null);

        foreach ($lines->filter(fn ($line) => $line['product'] === null) as $line) {
            $this->issue('error', 'product_not_linked', "Not created: \"{$line['title']}"
                . ($line['variant_title'] ? " - {$line['variant_title']}" : '')
                . '" '
                . ($line['variant_id']
                    ? "(variant {$line['variant_id']}) is not linked to a product in Laravel."
                    : '(no Shopify product / variant id).'));
        }

        if ($matched->isEmpty()) {
            $this->issue('error', 'nothing_done', 'Nothing done: no product of this order is linked to a Laravel product.');

            return $this->finish($order, 'failed');
        }


        /*
         * Governorate + zone.
         */
        [$province, $zone] = $this->resolveLocation($payload, $order);
        $order->province_id = $province?->id;
        $order->zone_id = $zone?->id;

        /*
         * Customer, address, pets, purchases.
         */
        // Delivery slot chosen on Shopify (order "Additional details")
        $slot = $this->deliverySlot($payload);

        if ($slot) {
            $this->issue('info', 'slot', "Delivery slot chosen on Shopify: {$slot}.");
        } elseif ($raw = $this->noteAttribute($payload, 'delivery_time_slot')) {
            $this->issue('warning', 'slot_unknown', "Delivery slot \"{$raw}\" not recognised: address slot used.");
        }

        $created = DB::transaction(function () use ($order, $payload, $matched, $province, $zone, $slot) {
            [$customer, $isNew] = $this->resolveCustomer($order, $province, $zone);

            $order->customer_account_id = $customer->id;
            $order->subscription_id = $customer->subscription_id;
            $order->customer_created = $order->customer_created || $isNew;

            $address = $this->resolveAddress($customer, $order, $province, $zone, $slot);

            $created = [];

            foreach ($matched as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $quantity = max(1, (int) $line['quantity']);

                // Already created (reprocess / webhook sent twice): only the missing packs
                $existing = Purchase::where('shopify_order_id', $order->shopify_order_id)
                    ->where('product_id', $product->id)
                    ->count();

                if ($existing >= $quantity) {
                    continue;
                }

                /*
                 * Quantity > 1: one pack per pet of this species and age
                 * (5 packs, 5 adult cats -> 1 each). Fewer pets: the extra
                 * packs follow for the same pet (next month).
                 */
                $pets = $quantity > 1
                    ? $this->petsForQuantity($customer, $product, $quantity)
                    : collect([$this->resolveAnimal($customer, $product)]);

                for ($unit = $existing + 1; $unit <= $quantity; $unit++) {
                    $animal = $pets[($unit - 1) % $pets->count()];
                    $purchase = $this->createPurchase($order, $payload, $line, $product, $customer, $animal, $unit, $quantity);

                    $created[] = ['purchase' => $purchase, 'animal' => $animal, 'address' => $address, 'customer' => $customer];
                }
            }

            return $created;
        });

        /*
         * Automatic planning (outside the transaction: one per purchase).
         */
        $orderDate = $order->ordered_at ? Carbon::parse($order->ordered_at) : now();

        // Several packs for the same pet: the next one starts when the previous meals are eaten
        $nextStartByPet = [];

        foreach ($created as $row) {
            $petId = $row['animal']->id;

            $result = $this->planner->plan(
                $row['purchase'],
                $row['customer'],
                $row['animal'],
                $orderDate,
                $row['address']?->id,
                'shopify',
                $nextStartByPet[$petId] ?? $this->planner->nextStartAfterPreviousPack($row['purchase']),
                $slot
            );

            if ($result['ok'] && $result['next_start']) {
                $nextStartByPet[$petId] = $result['next_start'];
            }

            $label = $row['purchase']->reference . ' (' . $row['purchase']->product_name . ')';

            if ($result['ok']) {
                $this->issue('info', 'planned', "{$label}: {$result['message']}");
            } else {
                $this->issue('warning', 'planning_failed', "{$label}: {$result['message']} The staff must plan the deliveries.");
            }
        }

        $order->purchases_count = Purchase::where('shopify_order_id', $order->shopify_order_id)->count();

        $hasProblem = collect($this->issues)->contains(fn ($issue) => in_array($issue['level'], ['error', 'warning'], true));

        $this->notifyStaff($order);

        return $this->finish($order, $hasProblem ? 'needs_review' : 'processed');
    }

    /*
    |--------------------------------------------------------------------------
    | ORDER SUMMARY (columns shown in the list)
    |--------------------------------------------------------------------------
    */

    public function fillSummary(ShopifyOrder $order, array $payload): void
    {
        $shipping = $payload['shipping_address'] ?? [];
        $billing = $payload['billing_address'] ?? [];
        $customer = $payload['customer'] ?? [];

        // The phone number is typed in the "email" field of the Shopify checkout
        $candidates = [
            $payload['email'] ?? null,
            $payload['contact_email'] ?? null,
            $customer['email'] ?? null,
            $payload['phone'] ?? null,
            $customer['phone'] ?? null,
            $shipping['phone'] ?? null,
            $billing['phone'] ?? null,
        ];

        $rawPhone = collect($candidates)->first(fn ($value) => KuwaitPhone::looksLikePhone($value))
            ?? ($payload['email'] ?? $payload['contact_email'] ?? $payload['phone'] ?? null);

        $order->fill([
            'shopify_order_name' => $payload['name'] ?? $order->shopify_order_name,
            'shopify_customer_id' => isset($customer['id']) ? (string) $customer['id'] : $order->shopify_customer_id,
            'financial_status' => $payload['financial_status'] ?? $order->financial_status,
            'total_price' => $payload['current_total_price'] ?? $payload['total_price'] ?? $order->total_price,
            'currency' => $payload['currency'] ?? $order->currency,
            'ordered_at' => isset($payload['created_at']) ? Carbon::parse($payload['created_at'])->setTimezone(config('app.timezone')) : $order->ordered_at,
            'first_name' => $shipping['first_name'] ?? $customer['first_name'] ?? $billing['first_name'] ?? null,
            'last_name' => $shipping['last_name'] ?? $customer['last_name'] ?? $billing['last_name'] ?? null,
            'phone_raw' => $rawPhone ? Str::limit((string) $rawPhone, 180, '') : null,
            'phone' => KuwaitPhone::international($rawPhone),
            'province_raw' => $shipping['province'] ?? $billing['province'] ?? null,
            'address_raw' => $this->addressText($payload) ?: null,
        ]);
    }

    private function addressText(array $payload): string
    {
        $address = !empty($payload['shipping_address']) ? $payload['shipping_address'] : ($payload['billing_address'] ?? []);

        return collect([
            $address['address1'] ?? null,
            $address['address2'] ?? null,
            $address['city'] ?? null,
            $address['zip'] ?? null,
        ])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->implode(', ');
    }

    /*
    |--------------------------------------------------------------------------
    | PRODUCTS
    |--------------------------------------------------------------------------
    */

    private function matchLines(array $payload): Collection
    {
        return collect($payload['line_items'] ?? [])->map(function (array $item) {
            $variantId = isset($item['variant_id']) ? (string) $item['variant_id'] : null;
            $productId = isset($item['product_id']) ? (string) $item['product_id'] : null;

            $product = null;

            if ($variantId) {
                $product = Product::where('shopify_variant_id', $variantId)->first();
            }

            if (!$product && $productId) {
                $product = Product::where('shopify_product_id', $productId)
                    ->where(fn ($q) => $q->whereNull('shopify_variant_id')->orWhere('shopify_variant_id', ''))
                    ->first();
            }

            $discount = collect($item['discount_allocations'] ?? [])->sum(fn ($d) => (float) ($d['amount'] ?? 0));

            if ($discount <= 0) {
                $discount = (float) ($item['total_discount'] ?? 0);
            }

            return [
                'line_id' => isset($item['id']) ? (string) $item['id'] : null,
                'title' => (string) ($item['title'] ?? $item['name'] ?? 'Product'),
                'variant_title' => $item['variant_title'] ?? null,
                'sku' => $item['sku'] ?? null,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => (int) ($item['quantity'] ?? 1),
                'price' => (float) ($item['price'] ?? 0),
                'discount' => round($discount, 3),
                'matched_product_id' => $product?->id,
                'matched_product_name' => $product?->name,
                'product' => $product,
            ];
        })->values();
    }

    /*
    |--------------------------------------------------------------------------
    | GOVERNORATE + ZONE
    |--------------------------------------------------------------------------
    |
    | Shopify sends the governorate, the zone is searched in the address
    | text (English and Arabic names of our zones).
    |
    */

    private const PROVINCE_ALIASES = [
        'capital' => ['capital', 'asimah', 'asima', 'kuwaitcity', 'العاصمه', 'عاصمه', 'مدينهالكويت'],
        'hawalli' => ['hawalli', 'hawally', 'hawali', 'حولي'],
        'farwaniya' => ['farwaniya', 'farwaniyah', 'farwania', 'فروانيه'],
        'ahmadi' => ['ahmadi', 'ahmedi', 'احمدي'],
        'jahra' => ['jahra', 'jahraa', 'جهراء', 'جهرا'],
        'mubarak' => ['mubarakalkabeer', 'mubarakalkabir', 'mubarakkabeer', 'mubarakkabir', 'مباركالكبير'],
    ];

    private const PROVINCE_CODES = [
        'KW-KU' => 'capital',
        'KW-HA' => 'hawalli',
        'KW-FA' => 'farwaniya',
        'KW-AH' => 'ahmadi',
        'KW-JA' => 'jahra',
        'KW-MU' => 'mubarak',
    ];

    /**
     * @return array{0: ?Province, 1: ?Zone}
     */
    private function resolveLocation(array $payload, ShopifyOrder $order): array
    {
        $address = !empty($payload['shipping_address']) ? $payload['shipping_address'] : ($payload['billing_address'] ?? []);

        $province = $this->matchProvince($order->province_raw, $address['province_code'] ?? null);

        if ($order->province_raw && !$province) {
            $this->issue('warning', 'province_not_found', "Governorate \"{$order->province_raw}\" not found in Laravel.");
        }

        if (!trim((string) $order->address_raw)) {
            $this->issue('warning', 'address_missing', 'No address in the Shopify order.');

            return [$province, null];
        }

        $zone = $this->matchZone(
            collect([$address['city'] ?? null, $order->address_raw])->filter()->implode(' '),
            $province
        );

        if (!$zone) {
            $this->issue('warning', 'zone_not_found', "Zone not found in the address \"{$order->address_raw}\": address saved with the governorate only.");

            return [$province, null];
        }

        if (!$province) {
            $province = $zone->province;
            $this->issue('info', 'province_from_zone', "Governorate taken from the zone {$zone->name}.");
        } elseif ((int) $zone->province_id !== (int) $province->id) {
            $this->issue(
                'warning',
                'zone_other_province',
                "Zone {$zone->name} belongs to {$zone->province?->name}, Shopify says {$province->name}: governorate of the zone used."
            );
            $province = $zone->province;
        }

        return [$province, $zone];
    }

    private function matchProvince(?string $raw, ?string $code): ?Province
    {
        $provinces = Province::all();

        $key = ($code && isset(self::PROVINCE_CODES[strtoupper($code)]))
            ? self::PROVINCE_CODES[strtoupper($code)]
            : $this->provinceKey($raw);

        foreach ($provinces as $province) {
            foreach ([$province->name, $province->name_ar] as $name) {
                if (!$name) {
                    continue;
                }

                if ($key && $this->provinceKey($name) === $key) {
                    return $province;
                }

                if ($raw && $this->compact($name) === $this->compact($raw)) {
                    return $province;
                }
            }
        }

        return null;
    }

    private function provinceKey(?string $value): ?string
    {
        $compact = $this->compact((string) $value);

        if ($compact === '') {
            return null;
        }

        foreach (self::PROVINCE_ALIASES as $key => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($compact, $alias)) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function matchZone(string $text, ?Province $province): ?Zone
    {
        $haystack = ' ' . $this->normalize($text) . ' ';
        $best = null;
        $bestScore = 0;

        foreach (Zone::with('province')->get() as $zone) {
            foreach ($this->zoneForms($zone) as $form) {
                if (mb_strlen($form) < 3 || !str_contains($haystack, ' ' . $form . ' ')) {
                    continue;
                }

                // Longest name wins, zones of the Shopify governorate first
                $score = mb_strlen($form) + ($province && (int) $zone->province_id === (int) $province->id ? 100 : 0);

                if ($score > $bestScore) {
                    $best = $zone;
                    $bestScore = $score;
                }
            }
        }

        return $best;
    }

    /** "Al Salmiya" -> ["al salmiya", "salmiya"], "السالمية" -> ["السالميه", "سالميه"] */
    private function zoneForms(Zone $zone): array
    {
        $forms = [];

        foreach ([$zone->name, $zone->name_ar] as $name) {
            $normal = $this->normalize((string) $name);

            if ($normal === '') {
                continue;
            }

            $forms[] = $normal;
            $forms[] = preg_replace('/^(al|el) /u', '', $normal);
            $forms[] = preg_replace('/^ال/u', '', $normal);
            $forms[] = str_replace(' ', '', $normal);
        }

        return array_values(array_unique(array_filter($forms)));
    }

    /**
     * Lower case, Arabic letters unified, punctuation removed,
     * "-ah"/"-iyah" endings unified (Salmiyah = Salmiya).
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);

        // Arabic
        $value = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $value);
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);

        // Punctuation -> space
        $value = preg_replace("/['’`]/u", '', $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        // English endings
        $value = preg_replace('/(?<=[a-z])ah\b/u', 'a', $value);
        $value = preg_replace('/\b(al|el)-/u', '$1 ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private function compact(string $value): string
    {
        $value = $this->normalize($value);
        $value = preg_replace('/\b(governorate|gov|muhafazat|province)\b/u', '', $value);
        $value = str_replace(['محافظه', 'محافظة'], '', $value);
        $value = preg_replace('/^(al|el) /u', '', trim($value));
        $value = preg_replace('/^ال/u', '', $value);

        return str_replace(' ', '', $value);
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{0: CustomerAccount, 1: bool} [account, created]
     */
    private function resolveCustomer(ShopifyOrder $order, ?Province $province, ?Zone $zone): array
    {
        $phone = $order->phone;

        // 1. App account with the same number
        $account = KuwaitPhone::whereSame(CustomerAccount::query(), 'phone', $phone)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        if ($account) {
            $this->issue('info', 'customer_existing', "Existing customer (account #{$account->id}).");
            $this->completeSubscription($account->subscription, $order, $province, $zone);

            return [$account, false];
        }

        // 2. Subscription (back-office / old Shopify) with the same number
        $subscription = KuwaitPhone::whereSame(Subscription::query(), 'subscriber_phone', $phone)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        if ($subscription) {
            $this->completeSubscription($subscription, $order, $province, $zone);

            $account = $subscription->customerAccount ?: $this->createAccount($subscription, $phone);

            $this->issue('info', 'customer_existing', "Existing subscription {$subscription->code}.");

            return [$account, false];
        }

        // 3. New customer
        $subscription = Subscription::create([
            'code' => SubscriptionCode::next($province, false),
            'creation_date' => now()->toDateString(),
            'subscriber_first_name' => $order->first_name ?: 'Customer',
            'subscriber_last_name' => $order->last_name,
            'subscriber_phone' => '+' . $phone,
            'shopify_customer_id' => $order->shopify_customer_id,
            'subscriber_address' => (string) ($order->address_raw ?? ''),
            'subscriber_note' => 'Created from Shopify order ' . $order->shopify_order_name,
            'subscriber_province' => $province?->name,
            'subscriber_zone' => $zone?->name,
            'subscriber_delivery_slot' => null,
            'delivery_days' => [],
            'day_recipes' => [],
        ]);

        $this->issue('info', 'customer_created', "New customer created: subscription {$subscription->code}.");

        return [$this->createAccount($subscription, $phone), true];
    }

    /** Shopify id / empty address of an existing subscription completed. */
    private function completeSubscription(?Subscription $subscription, ShopifyOrder $order, ?Province $province, ?Zone $zone): void
    {
        if (!$subscription) {
            return;
        }

        $changes = [];

        if (!$subscription->shopify_customer_id && $order->shopify_customer_id) {
            $changes['shopify_customer_id'] = $order->shopify_customer_id;
        }

        if (!trim((string) $subscription->subscriber_address) && $order->address_raw) {
            $changes['subscriber_address'] = $order->address_raw;
            $changes['subscriber_province'] = $province?->name;
            $changes['subscriber_zone'] = $zone?->name;
        }

        if ($province && SubscriptionCode::hasNoProvince($subscription->code)) {
            $changes['code'] = SubscriptionCode::withProvince($subscription->code, $province);
        }

        if ($changes) {
            $subscription->update($changes);
        }
    }

    /**
     * App account (sign in with a WhatsApp code). Random password,
     * never shown: the password sign in is not used.
     */
    private function createAccount(Subscription $subscription, string $phone): CustomerAccount
    {
        return CustomerAccount::create([
            'subscription_id' => $subscription->id,
            'phone' => $phone,
            'email' => null,
            'password' => Str::random(40),
            'settings' => ['language' => 'ar'],
            'initial_password_encrypted' => null,
            'password_changed_at' => null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ADDRESS
    |--------------------------------------------------------------------------
    */

    private function resolveAddress(CustomerAccount $customer, ShopifyOrder $order, ?Province $province, ?Zone $zone, ?string $slot = null): ?CustomerAddress
    {
        $text = trim((string) $order->address_raw);

        if ($text === '') {
            return $customer->addresses()->orderByDesc('is_default')->first();
        }

        $normal = $this->normalize($text);

        $addresses = $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get();

        // Same address text already saved
        $same = $addresses->first(fn ($address) => $this->normalize((string) $address->address) === $normal);

        /*
         * Same zone + same governorate as an address of the customer
         * (default first): the address of our system is used, no new one.
         */
        if (!$same && $zone) {
            $zoneNames = array_filter([$this->normalize((string) $zone->name), $this->normalize((string) $zone->name_ar)]);

            $same = $addresses->first(function ($address) use ($zone, $province, $zoneNames) {
                // Same zone (id, or zone name for old addresses without zone_id)
                $sameZone = $address->zone_id
                    ? (int) $address->zone_id === (int) $zone->id
                    : in_array($this->normalize((string) $address->city), $zoneNames, true);

                $sameProvince = !$province || !$address->province_id || (int) $address->province_id === (int) $province->id;

                return $sameZone && $sameProvince;
            });

            if ($same) {
                $this->issue('info', 'address_existing', 'Same zone (' . $zone->name . ') as the customer address "'
                    . ($same->label ?: Str::limit((string) $same->address, 30)) . '": this address is used (Shopify: "' . Str::limit($text, 60) . '").');
            }
        }

        if ($same) {
            if (!$same->zone_id && $zone) {
                $same->update(['zone_id' => $zone->id, 'province_id' => $province?->id ?? $same->province_id]);
            }

            // Slot chosen on Shopify, kept on the address when it has none
            if ($slot && !$same->delivery_slot) {
                $same->update(['delivery_slot' => $slot]);
            }

            return $same;
        }

        $isFirst = $addresses->isEmpty();

        $address = CustomerAddress::create([
            'customer_account_id' => $customer->id,
            // Label seen by the customer in the app: Home, Address 2, Address 3...
            'label' => $isFirst ? 'Home' : 'Address ' . ($addresses->count() + 1),
            'first_name' => $order->first_name,
            'last_name' => $order->last_name,
            'phone' => $order->phone,
            'address' => Str::limit($text, 495, ''),
            'note' => null,
            'delivery_slot' => $slot,
            'city' => $zone?->name,
            'province' => $province?->name,
            'province_id' => $province?->id,
            'zone_id' => $zone?->id,
            'country' => 'Kuwait',
            'is_default' => $isFirst,
        ]);

        if (!$isFirst) {
            $this->issue('info', 'address_added', 'New address added for this order (the default address was not changed).');
        }

        return $address;
    }

    /*
    |--------------------------------------------------------------------------
    | PET
    |--------------------------------------------------------------------------
    */

    /**
     * Pets receiving the packs of a quantity > 1:
     *  - enough pets of this species and age  -> one pack each
     *  - fewer pets                            -> packs shared, the extra ones
     *                                             follow for the same pet
     *  - no pet                                -> one new pet, packs one after the other
     */
    private function petsForQuantity(CustomerAccount $customer, Product $product, int $quantity): \Illuminate\Support\Collection
    {
        $pets = Animal::where('subscription_id', $customer->subscription_id)
            ->where('species', $product->species)
            ->orderBy('id')
            ->get()
            ->filter(fn (Animal $pet) => $product->fitsLifeStage($pet->age))
            ->values();

        if ($pets->isEmpty()) {
            $pet = $this->resolveAnimal($customer, $product);

            $this->issue('warning', 'quantity', "Quantity {$quantity}: no pet of this age yet, {$quantity} packs planned one after the other for \"{$pet->name}\". If the customer has {$quantity} pets, add them and plan again.");

            return collect([$pet]);
        }

        $pets = $pets->take($quantity)->values();
        $names = $pets->pluck('name')->implode(', ');

        $this->issue(
            $pets->count() === $quantity ? 'info' : 'warning',
            'quantity',
            $pets->count() === $quantity
                ? "Quantity {$quantity}: one pack for each pet ({$names})."
                : "Quantity {$quantity} for {$pets->count()} pet(s) ({$names}): the extra packs follow one after the other. Check with the customer."
        );

        return $pets;
    }

    private function resolveAnimal(CustomerAccount $customer, Product $product): Animal
    {
        $pets = Animal::where('subscription_id', $customer->subscription_id)
            ->where('species', $product->species)
            ->get();

        if ($pets->count() === 1) {
            $pet = $pets->first();
            $this->issue('info', 'pet_existing', "Pack given to the existing pet \"{$pet->name}\".");

            return $pet;
        }

        $stages = $product->lifeStagesList();

        $pet = Animal::create([
            'subscription_id' => $customer->subscription_id,
            'name' => $product->species === 'Dog' ? 'My Dog' : 'My Cat',
            'species' => $product->species,
            'age' => count($stages) === 1 ? $stages[0] : null,
            'breed_size_category' => $product->species === 'Dog' ? $product->breed_size_category : null,
            'health_status' => null,
            'transition' => false,
            'last_order' => false,
        ]);

        $this->issue(
            $pets->count() > 1 ? 'warning' : 'info',
            'pet_created',
            "New pet \"{$pet->name}\" created ({$product->species}"
            . ($pet->age ? ", {$pet->age}" : '') . ')'
            . ($pets->count() > 1 ? ': the customer already has several pets of this species, check which one.' : '.')
        );

        return $pet;
    }

    /*
    |--------------------------------------------------------------------------
    | PURCHASE (paid on Shopify)
    |--------------------------------------------------------------------------
    */

    private function createPurchase(
        ShopifyOrder $order,
        array $payload,
        array $line,
        Product $product,
        CustomerAccount $customer,
        Animal $animal,
        int $unit = 1,
        int $quantity = 1
    ): Purchase {
        $subscription = $customer->subscription;

        if ($product->max_purchases_per_customer) {
            $already = Purchase::where('customer_account_id', $customer->id)
                ->where('product_id', $product->id)
                ->whereIn('status', Purchase::COUNTED_STATUSES)
                ->count();

            if ($already >= $product->max_purchases_per_customer) {
                $this->issue('warning', 'purchase_limit', "\"{$product->name}\": the customer already bought it ({$already}x), limit {$product->max_purchases_per_customer}. Created anyway (paid on Shopify).");
            }
        }

        $unitPrice = $line['price'] > 0 ? $line['price'] : (float) $product->price;
        // Shopify discount = whole line: shared between the packs of the line
        $discount = min($unitPrice, round((float) $line['discount'] / max(1, $quantity), 3));
        $paidAt = isset($payload['processed_at']) ? Carbon::parse($payload['processed_at']) : ($order->ordered_at ?? now());

        $purchase = new Purchase();
        $purchase->fillFromProduct($product);

        $purchase->fill([
            'customer_account_id' => $customer->id,
            'subscription_id' => $customer->subscription_id,
            'customer_name' => $subscription
                ? trim($subscription->subscriber_first_name . ' ' . $subscription->subscriber_last_name)
                : $order->customerName(),
            'customer_phone' => $order->phone,

            'animal_id' => $animal->id,
            'animal_name' => $animal->name,

            'unit_price' => $unitPrice,
            'discount_amount' => $discount,
            'total_amount' => round($unitPrice - $discount, 3),
            'price_source' => abs($unitPrice - (float) $product->price) < 0.001 ? 'regular' : 'manual',
            'currency' => $order->currency ?: $product->currency,

            'source' => 'shopify',
            'payment_method' => 'shopify',
            'payment_reference' => $order->shopify_order_name . ($quantity > 1 ? " ({$unit}/{$quantity})" : ''),
            'paid_at' => $paidAt,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_order_name' => $order->shopify_order_name,

            'purchased_at' => $order->ordered_at ?? now(),
            'status' => 'paid',
            'status_changed_at' => now(),

            'customer_note' => $payload['note'] ?? null,
            'admin_note' => 'Automatic purchase: Shopify order ' . $order->shopify_order_name . ($quantity > 1 ? " - pack {$unit}/{$quantity}" : ''),
        ]);

        $purchase->save();

        $purchase->recordStatusHistory(
            null,
            'paid',
            ($order->financial_status === 'authorized' ? 'Payment authorized' : 'Paid') . ' on Shopify (order ' . $order->shopify_order_name . ').',
            'shopify'
        );

        $this->issue('info', 'purchase_created', "Purchase {$purchase->reference} created (paid): {$product->name} for {$animal->name}.");

        if ($order->financial_status === 'authorized') {
            $this->issue('warning', 'payment_authorized', 'Payment only AUTHORIZED on Shopify: capture it in Shopify (Orders > '
                . $order->shopify_order_name . ' > Capture payment) before it expires.');
        }

        return $purchase;
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /** Value of an order "Additional details" field (note_attributes). */
    private function noteAttribute(array $payload, string $name): ?string
    {
        foreach ($payload['note_attributes'] ?? [] as $attribute) {
            if (strtolower(trim((string) ($attribute['name'] ?? ''))) === strtolower($name)) {
                $value = trim((string) ($attribute['value'] ?? ''));

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * "delivery_time_slot" of the order -> one of our slots.
     * "12:00→3:00", "12:00 - 15:00", "12-3 PM" -> 12:00-15:00 ; "9:00→12:00" -> 09:00-12:00
     */
    private function deliverySlot(array $payload): ?string
    {
        $raw = $this->noteAttribute($payload, 'delivery_time_slot')
            ?? $this->noteAttribute($payload, 'delivery_slot')
            ?? $this->noteAttribute($payload, 'time_slot');

        if (!$raw || !preg_match('/(\d{1,2})(?::\d{2})?/', $raw, $m)) {
            return null;
        }

        $startHour = (int) $m[1];

        foreach (CustomerAddress::DELIVERY_SLOTS as $slot) {
            if ((int) substr($slot, 0, 2) === $startHour) {
                return $slot;
            }
        }

        return null;
    }

    /** Shopify payment statuses that create the purchase. */
    public static function acceptedFinancialStatuses(): array
    {
        $statuses = ['paid', 'partially_paid'];

        if (config('services.shopify.accept_authorized', true)) {
            $statuses[] = 'authorized';
        }

        return $statuses;
    }

    private function issue(string $level, string $code, string $message): void
    {
        $this->issues[] = ['level' => $level, 'code' => $code, 'message' => $message];
    }

    private function finish(ShopifyOrder $order, string $status, ?string $level = null, ?string $code = null, ?string $message = null): ShopifyOrder
    {
        if ($level && $message) {
            $this->issue($level, $code ?? 'info', $message);
        }

        $order->status = $status;
        $order->issues = $this->issues;
        $order->processed_at = now();

        // A new problem: back in the "to check" list
        if (in_array($status, ['needs_review', 'failed'], true)) {
            $order->reviewed_at = null;
            $order->reviewed_by_user_id = null;
        }

        $order->save();

        return $order;
    }

    /**
     * Hook for the staff alert (e-mail later).
     * The "Auto subscription" menu already shows a counter.
     */
    private function notifyStaff(ShopifyOrder $order): void
    {
        Log::info('Shopify order imported', [
            'order' => $order->shopify_order_name,
            'customer_account_id' => $order->customer_account_id,
        ]);

        // TODO e-mail: Mail::to(config('services.shopify.alert_email'))->send(new NewShopifyOrderMail($order));
    }
}

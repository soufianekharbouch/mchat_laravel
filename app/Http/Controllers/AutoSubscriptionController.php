<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Models\Province;
use App\Models\Purchase;
use App\Models\Subscription;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\PlannedOrderItem;
use App\Models\PlannedOrder;
use App\Models\ShopifyOrder;
use App\Services\AutoDeliveryPlanner;
use App\Services\PurchaseScheduleService;
use App\Services\ShopifyOrderImporter;
use App\Services\ShopifyOrderSync;
use App\Support\KuwaitPhone;
use App\Support\SubscriptionCode;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Auto subscription": Shopify orders imported by webhook.
 *
 * - list + status + "to check" counter
 * - detail: customer, address, products, created purchases, issues
 * - reprocess, mark as checked, ignore
 * - staff edits the planned deliveries (same rules as the app)
 * - staff fixes the order before validating it:
 *     customer name / phone / address (governorate + zone dropdowns)
 *     pets: add, rename, remove an empty one
 *     each pack given to another pet (Qty 2 = 2 cats), dates planned again
 */
class AutoSubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizePermission('auto_subscriptions.view');

        $filters = [
            'status' => (string) $request->query('status', ''),
            'review' => (string) $request->query('review', ''),
            'search' => trim((string) $request->query('search', '')),
        ];

        $orders = ShopifyOrder::query()
            ->with(['customerAccount.subscription', 'zone', 'province'])
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['review'] === 'todo', fn ($q) => $q->whereNull('reviewed_at')->where('status', '!=', 'ignored'))
            ->when($filters['review'] === 'done', fn ($q) => $q->whereNotNull('reviewed_at'))
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $search = '%' . $filters['search'] . '%';

                $q->where(fn ($w) => $w
                    ->where('shopify_order_name', 'like', $search)
                    ->orWhere('phone', 'like', $search)
                    ->orWhere('phone_raw', 'like', $search)
                    ->orWhere('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)
                    ->orWhere('address_raw', 'like', $search));
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $statusCounts = ShopifyOrder::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $toReviewCount = ShopifyOrder::unreviewedCount();

        return view('auto-subscriptions.index', compact('orders', 'filters', 'statusCounts', 'toReviewCount'));
    }

    public function show(ShopifyOrder $shopifyOrder, PurchaseScheduleService $schedule): View
    {
        $this->authorizePermission('auto_subscriptions.view');

        $shopifyOrder->load(['customerAccount.subscription', 'customerAccount.addresses', 'zone', 'province', 'reviewedBy']);

        $purchases = $shopifyOrder->purchases()
            ->with(['deliveries' => fn ($q) => $q->where('status', '!=', 'cancelled')->orderBy('delivery_date'), 'deliveries.items', 'deliveries.plannedOrder'])
            ->get();

        // Recipes of each pack + editable deliveries (staff editor: no 18:00 rule)
        $editor = $purchases->mapWithKeys(fn (Purchase $purchase) => [
            $purchase->id => [
                'recipes' => $schedule->allowedRecipes($purchase),
                'editable' => $purchase->deliveries->mapWithKeys(fn ($d) => [$d->id => $schedule->isEditable($d, true)]),
            ],
        ]);

        // Staff: tomorrow can always be chosen (order received at 22:00 -> delivered tomorrow)
        $earliest = $schedule->earliestDeliveryDate(true);

        $customer = $shopifyOrder->customerAccount;

        // Pets of the customer (the packs can be given to any of them)
        $pets = $customer?->subscription_id
            ? Animal::query()
                ->where('subscription_id', $customer->subscription_id)
                ->withCount('plannedOrders')
                ->orderBy('species')
                ->orderBy('id')
                ->get()
            : collect();

        // Packs of every pet (all orders): a pet with none can be removed
        $packsByPet = $pets->isEmpty()
            ? collect()
            : Purchase::query()
                ->whereIn('animal_id', $pets->pluck('id'))
                ->selectRaw('animal_id, COUNT(*) as total')
                ->groupBy('animal_id')
                ->pluck('total', 'animal_id');

        // Address used by the deliveries of this order (otherwise the default one)
        $usedAddressId = $purchases->flatMap->deliveries->pluck('customer_address_id')->filter()->first();
        $addresses = $customer?->addresses ?? collect();
        $currentAddress = $addresses->firstWhere('id', $usedAddressId)
            ?? $addresses->sortByDesc('is_default')->first();

        return view('auto-subscriptions.show', [
            'order' => $shopifyOrder,
            'purchases' => $purchases,
            'editor' => $editor,
            'earliest' => $earliest,
            'slots' => CustomerAddress::DELIVERY_SLOTS,
            'pets' => $pets,
            'packsByPet' => $packsByPet,
            'currentAddress' => $currentAddress,
            'provinces' => Province::query()->with(['zones' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get(),
            'lifeStages' => Product::LIFE_STAGES_BY_SPECIES,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER: name, phone, address (fixed by the staff before validating)
    |--------------------------------------------------------------------------
    */

    public function updateCustomer(Request $request, ShopifyOrder $shopifyOrder): RedirectResponse
    {
        $this->authorizePermission('auto_subscriptions.manage');

        $customer = $shopifyOrder->customerAccount;
        $subscription = $customer?->subscription;

        if (!$customer || !$subscription) {
            return back()->withErrors(['customer' => 'No customer for this order yet: fix the phone / products, then "Process again".']);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'address_id' => ['nullable', 'integer'],
            'address' => ['required', 'string', 'max:495'],
            'address_note' => ['nullable', 'string', 'max:500'],
            'province_id' => ['required', 'integer', 'exists:provinces,id'],
            'zone_id' => ['required', 'integer', 'exists:zones,id'],
            'delivery_slot' => ['nullable', Rule::in(CustomerAddress::DELIVERY_SLOTS)],
            'main_address' => ['nullable', 'boolean'],
            'validate_order' => ['nullable', 'boolean'],
        ], [], [
            'province_id' => 'governorate',
            'zone_id' => 'zone',
        ]);

        /*
         * Phone: 8 Kuwaiti digits. Already used by ANOTHER customer: refused
         * (otherwise the same person would have 2 subscriptions).
         */
        $phone = KuwaitPhone::international($data['phone']);

        if (!$phone) {
            return back()->withErrors(['phone' => 'Phone: 8 Kuwaiti digits expected (e.g. 50000000).'])->withInput();
        }

        $otherAccount = KuwaitPhone::whereSame(CustomerAccount::query(), 'phone', $phone)
            ->where('id', '!=', $customer->id)
            ->with('subscription')
            ->first();

        $otherSubscription = KuwaitPhone::whereSame(Subscription::query(), 'subscriber_phone', $phone)
            ->where('id', '!=', $subscription->id)
            ->first()
            ?? $otherAccount?->subscription;

        if ($otherAccount || $otherSubscription) {
            $owner = $otherSubscription
                ? $otherSubscription->code . ' (' . trim($otherSubscription->subscriber_first_name . ' ' . $otherSubscription->subscriber_last_name) . ')'
                : 'app account #' . $otherAccount->id;

            return back()->withErrors([
                'phone' => "The number +{$phone} already belongs to another customer: {$owner}. Not saved, to avoid a duplicate customer.",
            ])->withInput();
        }

        $province = Province::findOrFail($data['province_id']);
        $zone = Zone::findOrFail($data['zone_id']);

        if ((int) $zone->province_id !== (int) $province->id) {
            return back()->withErrors(['zone_id' => "The zone {$zone->name} is not in {$province->name}."])->withInput();
        }

        $purchases = Purchase::where('shopify_order_id', $shopifyOrder->shopify_order_id)
            ->with('deliveries.plannedOrder')
            ->get();

        $schedule = app(PurchaseScheduleService::class);
        $changes = [];

        DB::transaction(function () use ($data, $phone, $customer, $subscription, $province, $zone, $purchases, $schedule, $shopifyOrder, &$changes) {
            $firstName = trim($data['first_name']);
            $lastName = trim((string) ($data['last_name'] ?? '')) ?: null;
            $fullName = trim($firstName . ' ' . $lastName);

            /*
             * App account + subscription.
             */
            if (KuwaitPhone::local($customer->phone) !== KuwaitPhone::local($phone)) {
                $changes[] = 'phone +' . KuwaitPhone::international($customer->phone) . ' -> +' . $phone;
                $customer->forceFill(['phone' => $phone])->save();
            }

            $subscriptionChanges = [
                'subscriber_first_name' => $firstName,
                'subscriber_last_name' => $lastName,
                'subscriber_phone' => '+' . $phone,
            ];

            if (!empty($data['main_address'])) {
                $subscriptionChanges += [
                    'subscriber_address' => $data['address'],
                    'subscriber_province' => $province->name,
                    'subscriber_zone' => $zone->name,
                ];

                if (!empty($data['delivery_slot'])) {
                    $subscriptionChanges['subscriber_delivery_slot'] = $data['delivery_slot'];
                }
            }

            // Subscription code without governorate (C00...): completed
            if (SubscriptionCode::hasNoProvince($subscription->code)) {
                $subscriptionChanges['code'] = SubscriptionCode::withProvince($subscription->code, $province);
            }

            $subscription->fill($subscriptionChanges);

            if ($subscription->isDirty('subscriber_first_name') || $subscription->isDirty('subscriber_last_name')) {
                $changes[] = "name: {$fullName}";
            }

            $subscription->save();

            /*
             * Address: the one of the deliveries is corrected (the deliveries
             * keep pointing to it), otherwise a new one.
             */
            $addresses = $customer->addresses()->get();
            $address = !empty($data['address_id']) ? $addresses->firstWhere('id', (int) $data['address_id']) : null;

            $values = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'address' => $data['address'],
                'note' => $data['address_note'] ?? null,
                'delivery_slot' => $data['delivery_slot'] ?? null,
                'city' => $zone->name,
                'province' => $province->name,
                'province_id' => $province->id,
                'zone_id' => $zone->id,
                'country' => 'Kuwait',
            ];

            if ($address) {
                $address->fill($values);

                if ($address->isDirty()) {
                    $changes[] = 'address: ' . Str::limit($data['address'], 60) . " ({$zone->name}, {$province->name})";
                }

                $address->save();
            } else {
                $isFirst = $addresses->isEmpty();

                $address = CustomerAddress::create($values + [
                    'customer_account_id' => $customer->id,
                    'label' => $isFirst ? 'Home' : 'Address ' . ($addresses->count() + 1),
                    'is_default' => $isFirst,
                ]);

                $changes[] = 'new address: ' . Str::limit($data['address'], 60) . " ({$zone->name})";
            }

            /*
             * Deliveries of this order not prepared yet: this address.
             */
            $moved = 0;

            foreach ($purchases as $purchase) {
                foreach ($purchase->deliveries as $delivery) {
                    if ($delivery->status === 'cancelled' || !$schedule->isEditable($delivery, true)) {
                        continue;
                    }

                    if ((int) $delivery->customer_address_id !== (int) $address->id) {
                        $delivery->update(['customer_address_id' => $address->id]);
                        $moved++;
                    }
                }

                $purchase->forceFill([
                    'customer_name' => $fullName,
                    'customer_phone' => $phone,
                ])->save();
            }

            if ($moved) {
                $changes[] = "{$moved} delivery(ies) moved to this address";
            }

            /*
             * Shopify order (list + badges).
             */
            $shopifyOrder->forceFill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'province_id' => $province->id,
                'zone_id' => $zone->id,
                'subscription_id' => $subscription->id,
            ])->save();
        });

        if ($changes) {
            $this->logIssue($shopifyOrder, 'Customer corrected: ' . implode(' · ', $changes) . '.');
        }

        if (!empty($data['validate_order'])) {
            $this->markReviewed($shopifyOrder);

            return back()->with('success', 'Customer saved and order marked as checked.');
        }

        return back()->with('success', $changes ? 'Customer saved.' : 'Nothing changed.');
    }

    /*
    |--------------------------------------------------------------------------
    | PETS: add, edit, remove (empty pet), give a pack to another pet
    |--------------------------------------------------------------------------
    */

    public function storePet(
        Request $request,
        ShopifyOrder $shopifyOrder,
        PurchaseScheduleService $schedule,
        AutoDeliveryPlanner $planner
    ): RedirectResponse {
        $this->authorizePermission('auto_subscriptions.manage');

        $customer = $shopifyOrder->customerAccount;

        if (!$customer?->subscription_id) {
            return back()->withErrors(['pet' => 'No customer for this order yet.']);
        }

        $data = $this->validatePet($request) + $request->validate([
            'purchase_ids' => ['nullable', 'array'],
            'purchase_ids.*' => ['integer'],
        ]);

        $pet = Animal::create([
            'subscription_id' => $customer->subscription_id,
            'name' => $data['name'],
            'species' => $data['species'],
            'age' => $data['age'] ?? null,
            'sex' => $data['sex'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'breed_size_category' => $data['species'] === 'Dog' ? ($data['breed_size_category'] ?? null) : null,
            'allergies' => $data['allergies'] ?? null,
            'transition' => false,
            'last_order' => false,
        ]);

        $this->logIssue($shopifyOrder, "Pet \"{$pet->name}\" ({$pet->species}) added by the staff.");

        /*
         * Packs ticked in the form: given to the new pet + dates planned again.
         */
        $messages = [];
        $errors = [];

        $purchases = Purchase::where('shopify_order_id', $shopifyOrder->shopify_order_id)
            ->whereIn('id', $data['purchase_ids'] ?? [])
            ->orderBy('id')
            ->get();

        foreach ($purchases as $purchase) {
            $result = $this->movePurchase($shopifyOrder, $purchase, $pet, true, $schedule, $planner);
            if ($result['ok']) {
                $messages[] = $result['message'];
            } else {
                $errors[] = $result['message'];
            }
        }

        $redirect = back()->with('success', trim("Pet \"{$pet->name}\" added. " . implode(' ', $messages)));

        return $errors ? $redirect->withErrors(['pet' => implode(' ', $errors)]) : $redirect;
    }

    public function updatePet(Request $request, ShopifyOrder $shopifyOrder, Animal $animal): RedirectResponse
    {
        $this->authorizePermission('auto_subscriptions.manage');
        $this->checkPet($shopifyOrder, $animal);

        $data = $this->validatePet($request);
        $oldName = $animal->name;

        $animal->fill([
            'name' => $data['name'],
            'species' => $data['species'],
            'age' => $data['age'] ?? null,
            'sex' => $data['sex'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'breed_size_category' => $data['species'] === 'Dog' ? ($data['breed_size_category'] ?? null) : null,
            'allergies' => $data['allergies'] ?? $animal->allergies,
        ]);

        // Species of a pet with packs: never changed (the packs are for a cat / a dog)
        if ($animal->isDirty('species') && Purchase::where('animal_id', $animal->id)->exists()) {
            return back()->withErrors(['pet' => "{$oldName} already has packs: the species cannot be changed."]);
        }

        $animal->save();

        // Name kept on the packs (purchases list, daily report)
        if ($oldName !== $animal->name) {
            Purchase::where('animal_id', $animal->id)->update(['animal_name' => $animal->name]);
            $this->logIssue($shopifyOrder, "Pet \"{$oldName}\" renamed \"{$animal->name}\".");
        }

        return back()->with('success', "Pet \"{$animal->name}\" saved.");
    }

    /** Only a pet with no pack, no order and no meal calendar (e.g. "My Cat" created by mistake). */
    public function destroyPet(ShopifyOrder $shopifyOrder, Animal $animal): RedirectResponse
    {
        $this->authorizePermission('auto_subscriptions.manage');
        $this->checkPet($shopifyOrder, $animal);

        $used = Purchase::where('animal_id', $animal->id)->exists()
            || PlannedOrder::where('animal_id', $animal->id)->exists()
            || (Schema::hasTable('animal_meal_dates') && DB::table('animal_meal_dates')->where('animal_id', $animal->id)->exists());

        if ($used) {
            return back()->withErrors(['pet' => "{$animal->name} has packs or orders: give them to another pet first."]);
        }

        DB::transaction(function () use ($animal) {
            foreach (['pet_loyalty_transactions', 'pet_loyalty_accounts', 'loyalty_notifications', 'animal_meals'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'animal_id')) {
                    DB::table($table)->where('animal_id', $animal->id)->delete();
                }
            }

            $animal->delete();
        });

        $this->logIssue($shopifyOrder, "Pet \"{$animal->name}\" removed by the staff (no pack).");

        return back()->with('success', "Pet \"{$animal->name}\" removed.");
    }

    /** A pack of this order given to another pet of the customer. */
    public function assignPet(
        Request $request,
        ShopifyOrder $shopifyOrder,
        Purchase $purchase,
        PurchaseScheduleService $schedule,
        AutoDeliveryPlanner $planner
    ): RedirectResponse {
        $this->authorizePermission('auto_subscriptions.manage');
        $this->checkPurchase($shopifyOrder, $purchase);

        $data = $request->validate([
            'animal_id' => ['required', 'integer'],
            'replan' => ['nullable', 'boolean'],
        ]);

        $animal = Animal::find($data['animal_id']);

        if (!$animal) {
            return back()->withErrors(['pet' => 'Pet not found.']);
        }

        $this->checkPet($shopifyOrder, $animal);

        $result = $this->movePurchase($shopifyOrder, $purchase, $animal, !empty($data['replan']), $schedule, $planner);

        return $result['ok']
            ? back()->with('success', $result['message'])
            : back()->withErrors(['pet' => $result['message']]);
    }

    /**
     * Pack -> other pet:
     *  - refused when a delivery is already prepared / delivered
     *  - the daily report orders follow the new pet
     *  - replan: dates planned again for the new pet (pack 2/2 of the same
     *    cat started after pack 1: for another cat it starts now)
     *
     * @return array{ok: bool, message: string}
     */
    private function movePurchase(
        ShopifyOrder $order,
        Purchase $purchase,
        Animal $animal,
        bool $replan,
        PurchaseScheduleService $schedule,
        AutoDeliveryPlanner $planner
    ): array {
        $label = $purchase->reference;

        if ((int) $purchase->animal_id === (int) $animal->id) {
            return ['ok' => true, 'message' => "{$label} is already for {$animal->name}."];
        }

        if ($purchase->species && $animal->species !== $purchase->species) {
            return ['ok' => false, 'message' => "{$label} is a {$purchase->species} pack: it cannot be given to {$animal->name} ({$animal->species})."];
        }

        $purchase->load('deliveries.plannedOrder');
        $active = $purchase->deliveries->where('status', '!=', 'cancelled');
        $locked = $active->filter(fn ($delivery) => !$schedule->isEditable($delivery, true));

        if ($locked->isNotEmpty()) {
            return [
                'ok' => false,
                'message' => "{$label}: " . $locked->count() . ' delivery(ies) already prepared or delivered ('
                    . $locked->map(fn ($d) => Carbon::parse($d->delivery_date)->format('d/m'))->implode(', ')
                    . '): the pack cannot change pet.',
            ];
        }

        $plannedOrderIds = $active->pluck('planned_order_id')->filter()->values();

        // Dates kept: the new pet must be free those days
        if (!$replan && $plannedOrderIds->isNotEmpty()) {
            $busy = PlannedOrder::where('animal_id', $animal->id)
                ->whereNotIn('id', $plannedOrderIds)
                ->whereIn('scheduled_for', $active->map(fn ($d) => Carbon::parse($d->delivery_date)->format('Y-m-d'))->all())
                ->pluck('scheduled_for')
                ->map(fn ($date) => Carbon::parse($date)->format('d/m'));

            if ($busy->isNotEmpty()) {
                return ['ok' => false, 'message' => "{$animal->name} already has a delivery on " . $busy->implode(', ') . ': tick "Plan the dates again".'];
            }
        }

        $oldPetId = (int) $purchase->animal_id;
        $oldName = $purchase->animal_name ?: 'no pet';

        DB::transaction(function () use ($purchase, $animal, $plannedOrderIds) {
            $purchase->forceFill([
                'animal_id' => $animal->id,
                'animal_name' => $animal->name,
            ])->save();

            if ($plannedOrderIds->isNotEmpty()) {
                PlannedOrder::whereIn('id', $plannedOrderIds)->update(['animal_id' => $animal->id]);
            }
        });

        $message = "{$label} given to {$animal->name} (before: {$oldName}).";

        /*
         * Dates planned again for the new pet.
         */
        if ($replan && in_array($purchase->status, ['paid', 'active'], true) && $purchase->customerAccount) {
            $fresh = $purchase->fresh();

            $result = $planner->plan(
                $fresh,
                $fresh->customerAccount,
                $animal,
                $order->ordered_at ? Carbon::parse($order->ordered_at) : now(),
                $fresh->deliveries()->where('status', '!=', 'cancelled')->value('customer_address_id')
                    ?? $fresh->customerAccount->addresses()->orderByDesc('is_default')->value('id'),
                'admin',
                $planner->nextStartAfterPreviousPack($fresh)
            );

            $message .= ' ' . ($result['ok'] ? $result['message'] : 'Dates not planned again: ' . $result['message'] . ' Plan them below.');
        }

        // Points of the old pet recalculated too
        if ($oldPetId && class_exists(\App\Services\PetLoyaltyService::class)) {
            try {
                app(\App\Services\PetLoyaltyService::class)->rebuildPetSafely($oldPetId);
            } catch (\Throwable) {
                // points recalculated tonight
            }
        }

        $this->logIssue($order, $message);

        return ['ok' => true, 'message' => $message];
    }

    private function validatePet(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'species' => ['required', Rule::in(Product::SPECIES)],
            'age' => ['nullable', Rule::in(Product::LIFE_STAGES)],
            'sex' => ['nullable', Rule::in(['Male', 'Female'])],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:120'],
            'breed_size_category' => ['nullable', Rule::in(Product::BREED_SIZES)],
            'allergies' => ['nullable', 'string', 'max:500'],
        ]);

        // Kitten = cat, Puppy = dog
        if (!empty($data['age']) && !in_array($data['age'], Product::LIFE_STAGES_BY_SPECIES[$data['species']] ?? [], true)) {
            throw ValidationException::withMessages(['age' => "Age \"{$data['age']}\" is not for a {$data['species']}."]);
        }

        $data['name'] = trim($data['name']);

        return $data;
    }

    /** The pet must belong to the customer of this order. */
    private function checkPet(ShopifyOrder $shopifyOrder, Animal $animal): void
    {
        $subscriptionId = $shopifyOrder->customerAccount?->subscription_id;

        abort_unless($subscriptionId && (int) $animal->subscription_id === (int) $subscriptionId, 404);
    }

    /** One line in "What was done" (history of the staff corrections). */
    private function logIssue(ShopifyOrder $order, string $message): void
    {
        $order->refresh();

        $order->forceFill([
            'issues' => array_merge($order->issues ?? [], [[
                'level' => 'info',
                'code' => 'staff_edit',
                'message' => $message . ' — ' . (auth()->user()->name ?? 'staff') . ', ' . now()->format('d/m/Y H:i'),
            ]]),
        ])->save();
    }

    private function markReviewed(ShopifyOrder $order): void
    {
        $order->forceFill([
            'reviewed_at' => now(),
            'reviewed_by_user_id' => auth()->id(),
        ])->save();
    }

    /**
     * "Refresh": Shopify orders whose webhook was not received
     * (only since the start of the auto subscription, last days).
     */
    public function sync(ShopifyOrderSync $sync): RedirectResponse
    {
        $this->authorizePermission('auto_subscriptions.manage');

        try {
            $result = $sync->run();
        } catch (\Throwable $exception) {
            return back()->withErrors(['sync' => 'Refresh failed: ' . $exception->getMessage()]);
        }

        $from = $result['from']->format('d/m/Y');

        $message = $result['created'] || $result['updated']
            ? "{$result['created']} new order(s) imported"
                . ($result['updated'] ? ", {$result['updated']} paid order(s) processed again" : '')
                . " (Shopify orders since {$from})."
            : "Nothing new: the {$result['checked']} Shopify order(s) since {$from} are all here.";

        $redirect = redirect()->route('auto-subscriptions.index')->with('success', $message);

        return $result['errors'] ? $redirect->withErrors(['sync' => implode(' ', $result['errors'])]) : $redirect;
    }

    /** Runs the import again (missing products linked, address fixed...). */
    public function reprocess(ShopifyOrder $shopifyOrder, ShopifyOrderImporter $importer): RedirectResponse
    {
        $this->authorizePermission('auto_subscriptions.manage');

        $importer->process($shopifyOrder, true);

        return back()->with('success', 'Order processed again: see the result below.');
    }

    public function review(Request $request, ShopifyOrder $shopifyOrder): RedirectResponse
    {
        $this->authorizePermission('auto_subscriptions.manage');

        $shopifyOrder->update([
            'reviewed_at' => now(),
            'reviewed_by_user_id' => auth()->id(),
            'admin_note' => $request->input('admin_note', $shopifyOrder->admin_note),
        ]);

        return back()->with('success', 'Order marked as checked.');
    }

    public function ignore(Request $request, ShopifyOrder $shopifyOrder): RedirectResponse
    {
        $this->authorizePermission('auto_subscriptions.manage');

        /*
         * Ignored = nothing for this order: the purchases already created and
         * their deliveries (daily report orders) are removed.
         * Not possible when a delivery is already prepared / shipped / delivered.
         */
        $purchases = Purchase::where('shopify_order_id', $shopifyOrder->shopify_order_id)
            ->with('deliveries.plannedOrder')
            ->get();

        $locked = $purchases->flatMap->deliveries
            ->filter(fn ($delivery) => $delivery->status === 'delivered'
                || in_array($delivery->plannedOrder?->status, ['prepared', 'shipped', 'delivered'], true));

        if ($locked->isNotEmpty()) {
            return back()->withErrors([
                'ignore' => 'Cannot ignore: ' . $locked->count() . ' delivery(ies) already prepared or delivered ('
                    . $locked->map(fn ($d) => \Carbon\Carbon::parse($d->delivery_date)->format('d/m'))->implode(', ')
                    . '). Cancel the purchase from Purchases instead.',
            ]);
        }

        $removed = DB::transaction(function () use ($purchases) {
            $count = 0;

            foreach ($purchases as $purchase) {
                $plannedOrderIds = $purchase->deliveries->pluck('planned_order_id')->filter()->values();

                // JAAK links (never sent: orders not prepared) + daily report orders
                DB::table('jaak_delivery_orders')->whereIn('planned_order_id', $plannedOrderIds)->delete();
                PlannedOrderItem::whereIn('planned_order_id', $plannedOrderIds)->delete();
                PlannedOrder::whereIn('id', $plannedOrderIds)->delete();

                // Pack deliveries + recipes, status history, purchase
                foreach ($purchase->deliveries as $delivery) {
                    $delivery->items()->delete();
                    $delivery->delete();
                }

                DB::table('purchase_status_histories')->where('purchase_id', $purchase->id)->delete();
                $purchase->delete();
                $count++;
            }

            return $count;
        });

        $shopifyOrder->forceFill([
            'status' => 'ignored',
            'purchases_count' => 0,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => auth()->id(),
            'admin_note' => $request->input('admin_note', $shopifyOrder->admin_note),
            'issues' => array_merge($shopifyOrder->issues ?? [], [[
                'level' => 'info',
                'code' => 'ignored',
                'message' => 'Order ignored by ' . (auth()->user()->name ?? 'staff') . ' on ' . now()->format('d/m/Y H:i')
                    . ($removed ? ": {$removed} purchase(s) and their deliveries removed." : ': nothing created.'),
            ]]),
        ])->save();

        return back()->with('success', 'Order ignored' . ($removed ? " - {$removed} purchase(s) and their deliveries removed." : '.'));
    }

    /** Automatic planning again for one purchase (editable deliveries replaced). */
    public function replan(ShopifyOrder $shopifyOrder, Purchase $purchase, AutoDeliveryPlanner $planner): RedirectResponse
    {
        $this->authorizePermission('auto_subscriptions.manage');
        $this->checkPurchase($shopifyOrder, $purchase);

        if (!$purchase->customerAccount || !$purchase->animal) {
            return back()->withErrors(['planning' => 'Customer account or pet missing on this purchase.']);
        }

        $result = $planner->plan(
            $purchase,
            $purchase->customerAccount,
            $purchase->animal,
            $shopifyOrder->ordered_at ? Carbon::parse($shopifyOrder->ordered_at) : now(),
            $purchase->deliveries()->where('status', '!=', 'cancelled')->value('customer_address_id'),
            'admin',
            // Pack 2/5 of the same pet: after the meals of the previous pack
            $planner->nextStartAfterPreviousPack($purchase)
        );

        return $result['ok']
            ? back()->with('success', $result['message'])
            : back()->withErrors(['planning' => $result['message']]);
    }

    /**
     * Staff editor: dates, slot, recipes of each delivery.
     * deliveries[n][id|date|delivery_slot|address_id|remove] + deliveries[n][items][recipe_id] = qty
     */
    public function saveDeliveries(
        Request $request,
        ShopifyOrder $shopifyOrder,
        Purchase $purchase,
        PurchaseScheduleService $schedule
    ): RedirectResponse {
        $this->authorizePermission('auto_subscriptions.manage');
        $this->checkPurchase($shopifyOrder, $purchase);

        $customer = $purchase->customerAccount;

        if (!$customer) {
            return back()->withErrors(['planning' => 'This purchase has no customer account.']);
        }

        $rows = collect($request->input('deliveries', []))
            ->reject(fn ($row) => !empty($row['remove']) || empty($row['date']))
            ->map(fn ($row) => [
                'id' => !empty($row['id']) ? (int) $row['id'] : null,
                'date' => $row['date'],
                'delivery_slot' => ($row['delivery_slot'] ?? '') !== '' ? $row['delivery_slot'] : null,
                'address_id' => !empty($row['address_id']) ? (int) $row['address_id'] : null,
                'items' => collect($row['items'] ?? [])
                    ->map(fn ($qty, $recipeId) => ['recipe_id' => (int) $recipeId, 'quantity' => (int) $qty])
                    ->filter(fn ($item) => $item['quantity'] > 0)
                    ->values()
                    ->all(),
            ])
            ->filter(fn ($row) => !empty($row['items']))
            ->values()
            ->all();

        try {
            $schedule->saveSchedule($purchase, $rows, $customer, $purchase->animal_id, 'admin');
        } catch (ValidationException $exception) {
            return back()->withErrors(['planning' => collect($exception->errors())->flatten()->first()])->withInput();
        }

        return back()->with('success', "Deliveries of {$purchase->reference} saved.");
    }

    private function checkPurchase(ShopifyOrder $shopifyOrder, Purchase $purchase): void
    {
        abort_unless((string) $purchase->shopify_order_id === (string) $shopifyOrder->shopify_order_id, 404);
    }

    private function authorizePermission(string $permission): void
    {
        $user = auth()->user();

        abort_unless($user && $user->hasPermission($permission), 403);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Subscription;
use App\Support\ProductPricing;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class PurchaseController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | LIST
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): View
    {
        $this->authorizePermission('purchases.view');

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'source' => (string) $request->query('source', ''),
            'payment_method' => (string) $request->query('payment_method', ''),
            'product_id' => (string) $request->query('product_id', ''),
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
            'premium' => (string) $request->query('premium', ''), // pending | done | all
        ];

        $query = Purchase::query()
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $search = '%' . $filters['search'] . '%';

                $query->where(function ($q) use ($search) {
                    $q->where('reference', 'like', $search)
                        ->orWhere('customer_name', 'like', $search)
                        ->orWhere('customer_phone', 'like', $search)
                        ->orWhere('animal_name', 'like', $search)
                        ->orWhere('product_name', 'like', $search)
                        ->orWhere('product_code', 'like', $search)
                        ->orWhere('payment_reference', 'like', $search)
                        ->orWhere('shopify_order_name', 'like', $search)
                        ->orWhereHas('subscription', fn ($s) => $s->where('code', 'like', $search));
                });
            })
            ->when(array_key_exists($filters['source'], Purchase::SOURCES),
                fn ($query) => $query->where('source', $filters['source']))
            ->when(array_key_exists($filters['payment_method'], Purchase::PAYMENT_METHODS),
                fn ($query) => $query->where('payment_method', $filters['payment_method']))
            ->when(ctype_digit($filters['product_id']),
                fn ($query) => $query->where('product_id', (int) $filters['product_id']))
            ->when($this->isDate($filters['date_from']),
                fn ($query) => $query->whereDate('purchased_at', '>=', $filters['date_from']))
            ->when($this->isDate($filters['date_to']),
                fn ($query) => $query->whereDate('purchased_at', '<=', $filters['date_to']))
            // PREMIUM-... packs
            ->when(in_array($filters['premium'], ['pending', 'done'], true),
                fn ($query) => $query->where('premium_status', $filters['premium']))
            ->when($filters['premium'] === 'all',
                fn ($query) => $query->whereNotNull('premium_status'));

        /*
         * Counters per status (with the other filters applied).
         */
        $statusCounts = (clone $query)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $purchases = $query
            ->when(array_key_exists($filters['status'], Purchase::STATUSES),
                fn ($query) => $query->where('status', $filters['status']))
            ->with(['subscription:id,code'])
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $products = Product::withTrashed()->orderBy('name')->get(['id', 'code', 'name', 'deleted_at']);

        $premiumPendingCount = \App\Support\PremiumPack::pendingCount();

        return view('purchases.index', compact('purchases', 'filters', 'statusCounts', 'products', 'premiumPendingCount'));
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE (manual purchase from the back-office)
    |--------------------------------------------------------------------------
    */

    public function create(Request $request): View
    {
        $this->authorizePermission('purchases.create');

        return view('purchases.create', [
            'customers' => $this->customersForForm(),
            'products' => Product::query()->orderBy('species')->orderBy('sort_order')->orderBy('name')->get(),
            'preselectedSubscriptionId' => $request->query('subscription_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('purchases.create');

        $validator = Validator::make($request->all(), [
            'subscription_id' => ['required', 'integer', 'exists:subscriptions,id'],
            'animal_id' => ['nullable', 'integer', 'exists:animals,id'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],

            'source' => ['required', Rule::in(array_keys(Purchase::SOURCES))],
            'initial_status' => ['required', Rule::in(['pending', 'paid', 'active'])],

            'unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', 'lte:unit_price'],

            'purchased_at' => ['required', 'date'],
            'starts_on' => ['nullable', 'date'],
        ] + $this->paymentRules(), [
            'discount_amount.lte' => 'The discount cannot be higher than the price.',
        ]);

        $validator->after(function ($validator) use ($request) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateAnimalAndProduct($validator, $request);
            $this->validatePaidStatus($validator, $request, $request->initial_status);
        });

        $data = $validator->validate();

        $subscription = Subscription::with('customerAccount')->findOrFail($data['subscription_id']);
        $product = Product::findOrFail($data['product_id']);
        $animal = !empty($data['animal_id']) ? Animal::find($data['animal_id']) : null;

        $purchase = DB::transaction(function () use ($request, $data, $subscription, $product, $animal) {
            $unitPrice = (float) $data['unit_price'];
            $discount = (float) ($data['discount_amount'] ?? 0);

            $purchase = new Purchase();
            $purchase->fillFromProduct($product);

            $purchase->fill([
                'customer_account_id' => $subscription->customerAccount?->id,
                'subscription_id' => $subscription->id,
                'customer_name' => trim($subscription->subscriber_first_name . ' ' . $subscription->subscriber_last_name),
                'customer_phone' => $subscription->subscriber_phone,

                'animal_id' => $animal?->id,
                'animal_name' => $animal?->name,

                // Price typed by the admin (product price by default)
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'total_amount' => max(0, round($unitPrice - $discount, 3)),
                'price_source' => 'manual',
                'trial_purchase_id' => null,

                'source' => $data['source'],
                'payment_method' => $data['payment_method'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'paid_at' => $data['paid_at'] ?? null,

                'shopify_order_id' => $data['shopify_order_id'] ?? null,
                'shopify_order_name' => $data['shopify_order_name'] ?? null,

                'purchased_at' => $data['purchased_at'],
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => !empty($data['starts_on'])
                    ? $purchase->computeEndDate($data['starts_on'])
                    : null,

                'customer_note' => $data['customer_note'] ?? null,
                'admin_note' => $data['admin_note'] ?? null,

                'status' => 'pending',
                'status_changed_at' => now(),
                'created_by_user_id' => auth()->id(),
            ]);

            /*
             * Typed price = automatic price for this pet: keep its origin
             * (regular / promo / trial offer, the trial offer is then "used").
             */
            $pricing = ProductPricing::for($product, $animal);

            if (abs($unitPrice - $pricing['price']) < 0.0005) {
                $purchase->price_source = $pricing['source'];
                $purchase->trial_purchase_id = $pricing['trial_purchase']?->id;
            }

            if ($request->hasFile('payment_proof')) {
                $purchase->payment_proof = $request->file('payment_proof')->store('purchase_proofs', 'public');
            }

            $purchase->save();

            $purchase->recordStatusHistory(null, 'pending', 'Purchase created from the back-office.', 'admin', auth()->id());

            /*
             * Requested initial status: each step is written in the history.
             */
            if (in_array($data['initial_status'], ['paid', 'active'], true)) {
                $purchase->changeStatus('paid', null, 'admin', auth()->id());
            }

            if ($data['initial_status'] === 'active') {
                $purchase->changeStatus('active', null, 'admin', auth()->id());
            }

            return $purchase;
        });

        return redirect()
            ->route('purchases.show', $purchase)
            ->with('success', "Purchase {$purchase->reference} created.");
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    */

    public function show(Purchase $purchase): View
    {
        $this->authorizePermission('purchases.view');

        $purchase->load([
            'product',
            'subscription.customerAccount',
            'animal',
            'createdBy',
            'statusHistories.changedBy',
            'deliveries.items',
            'deliveries.customerAddress',
        ]);

        $scheduledRecipes = (int) $purchase->deliveries
            ->where('status', '!=', 'cancelled')
            ->sum(fn ($delivery) => $delivery->items->sum('quantity'));

        return view('purchases.show', compact('purchase', 'scheduledRecipes'));
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT (payment, price, dates, notes — not the customer / product)
    |--------------------------------------------------------------------------
    */

    public function edit(Purchase $purchase): View
    {
        $this->authorizePermission('purchases.update');

        $purchase->load(['subscription.animals']);

        $animals = collect($purchase->subscription?->animals ?? [])
            ->filter(fn ($animal) => ($animal->species ?? null) === $purchase->species)
            ->values();

        return view('purchases.edit', compact('purchase', 'animals'));
    }

    public function update(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorizePermission('purchases.update');

        $validator = Validator::make($request->all(), [
            'animal_id' => ['nullable', 'integer', 'exists:animals,id'],

            'unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', 'lte:unit_price'],

            'purchased_at' => ['required', 'date'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],

            'remove_payment_proof' => ['nullable', 'boolean'],
        ] + $this->paymentRules(), [
            'discount_amount.lte' => 'The discount cannot be higher than the price.',
        ]);

        $validator->after(function ($validator) use ($request, $purchase) {
            if ($validator->errors()->isNotEmpty() || !filled($request->animal_id)) {
                return;
            }

            $animal = Animal::find($request->animal_id);

            if (!$animal || (int) $animal->subscription_id !== (int) $purchase->subscription_id) {
                $validator->errors()->add('animal_id', 'This animal does not belong to the customer.');
            } elseif (($animal->species ?? null) !== $purchase->species) {
                $validator->errors()->add('animal_id', "This pack is for a {$purchase->species}.");
            }
        });

        $data = $validator->validate();

        DB::transaction(function () use ($request, $purchase, $data) {
            $oldProof = $purchase->payment_proof;

            $unitPrice = (float) $data['unit_price'];
            $discount = (float) ($data['discount_amount'] ?? 0);
            $animal = !empty($data['animal_id']) ? Animal::find($data['animal_id']) : null;

            $startsOn = $data['starts_on'] ?? null;
            $endsOn = $data['ends_on'] ?? null;

            // Start date changed without an end date: end computed from the duration
            if ($startsOn && !$endsOn) {
                $endsOn = $purchase->computeEndDate($startsOn);
            }

            $purchase->fill([
                'animal_id' => $animal?->id,
                'animal_name' => $animal?->name,

                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'total_amount' => max(0, round($unitPrice - $discount, 3)),

                'payment_method' => $data['payment_method'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'paid_at' => $data['paid_at'] ?? $purchase->paid_at,

                'shopify_order_id' => $data['shopify_order_id'] ?? null,
                'shopify_order_name' => $data['shopify_order_name'] ?? null,

                'purchased_at' => $data['purchased_at'],
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,

                'customer_note' => $data['customer_note'] ?? null,
                'admin_note' => $data['admin_note'] ?? null,
            ]);

            if ($request->boolean('remove_payment_proof')) {
                $purchase->payment_proof = null;
            }

            if ($request->hasFile('payment_proof')) {
                $purchase->payment_proof = $request->file('payment_proof')->store('purchase_proofs', 'public');
            }

            $purchase->save();

            if ($oldProof && $oldProof !== $purchase->payment_proof) {
                Storage::disk('public')->delete($oldProof);
            }
        });

        return redirect()
            ->route('purchases.show', $purchase)
            ->with('success', 'Purchase updated.');
    }

    /*
    |--------------------------------------------------------------------------
    | STATUS CHANGE
    |--------------------------------------------------------------------------
    */

    public function updateStatus(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorizePermission('purchases.update_status');

        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in($purchase->allowedTransitions())],
            'note' => [
                Rule::requiredIf(in_array($request->status, Purchase::STATUSES_REQUIRING_NOTE, true)),
                'nullable', 'string', 'max:2000',
            ],
        ], [
            'status.in' => 'This status change is not allowed.',
            'note.required' => 'Please give the reason.',
        ]);

        $validator->after(function ($validator) use ($request, $purchase) {
            if ($validator->errors()->isEmpty()) {
                $this->validatePaidStatus($validator, $request, $request->status, $purchase);
            }
        });

        $data = $validator->validate();

        try {
            $purchase->changeStatus($data['status'], $data['note'] ?? null, 'admin', auth()->id());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        return redirect()
            ->route('purchases.show', $purchase)
            ->with('success', 'Status changed to ' . Purchase::statusLabel($purchase->status) . '.');
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATION HELPERS
    |--------------------------------------------------------------------------
    */

    private function paymentRules(): array
    {
        return [
            'payment_method' => ['nullable', Rule::in(array_keys(Purchase::PAYMENT_METHODS))],
            'payment_reference' => ['nullable', 'string', 'max:191'],
            'payment_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'paid_at' => ['nullable', 'date'],

            'shopify_order_id' => ['nullable', 'string', 'max:100'],
            'shopify_order_name' => ['nullable', 'string', 'max:100'],

            'customer_note' => ['nullable', 'string', 'max:2000'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Animal of the customer, same species as the pack, product purchase limit.
     */
    private function validateAnimalAndProduct($validator, Request $request): void
    {
        $subscription = Subscription::find($request->subscription_id);
        $product = Product::find($request->product_id);

        if (!$subscription || !$product) {
            return;
        }

        if (filled($request->animal_id)) {
            $animal = Animal::find($request->animal_id);

            if (!$animal || (int) $animal->subscription_id !== (int) $subscription->id) {
                $validator->errors()->add('animal_id', 'This animal does not belong to the selected customer.');
            } elseif (($animal->species ?? null) !== $product->species) {
                $validator->errors()->add(
                    'product_id',
                    "\"{$product->name}\" is a {$product->species} pack, {$animal->name} is a {$animal->species}."
                );
            } elseif (
                $product->species === 'Dog'
                && $product->breed_size_category
                && filled($animal->breed_size_category ?? null)
                && $animal->breed_size_category !== $product->breed_size_category
            ) {
                $validator->errors()->add(
                    'product_id',
                    "This pack is for {$product->breed_size_category} dogs, {$animal->name} is {$animal->breed_size_category}."
                );
            }
        }

        if ($product->max_purchases_per_customer) {
            $alreadyBought = Purchase::query()
                ->where('product_id', $product->id)
                ->where('subscription_id', $subscription->id)
                ->whereIn('status', Purchase::COUNTED_STATUSES)
                ->count();

            if ($alreadyBought >= $product->max_purchases_per_customer) {
                $validator->errors()->add(
                    'product_id',
                    "This customer already bought this product {$alreadyBought} time(s) "
                    . "(limit: {$product->max_purchases_per_customer})."
                );
            }
        }
    }

    /**
     * Paid / active: a payment method is required.
     */
    private function validatePaidStatus($validator, Request $request, ?string $status, ?Purchase $purchase = null): void
    {
        if (!in_array($status, ['paid', 'active'], true)) {
            return;
        }

        $method = $request->input('payment_method', $purchase?->payment_method);

        if (!filled($method)) {
            $validator->errors()->add(
                'payment_method',
                'Set the payment method before marking the purchase as paid'
                . ($purchase ? ' (Edit the purchase first).' : '.')
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DATA HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Customers (subscriptions) with their animals, for the create form search.
     */
    private function customersForForm(): array
    {
        return Subscription::query()
            ->with(['animals', 'customerAccount'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($subscription) => [
                'id' => $subscription->id,
                'code' => (string) $subscription->code,
                'name' => trim($subscription->subscriber_first_name . ' ' . $subscription->subscriber_last_name),
                'phone' => (string) $subscription->subscriber_phone,
                'has_account' => (bool) $subscription->customerAccount,
                'animals' => collect($subscription->animals)->map(fn ($animal) => [
                    'id' => $animal->id,
                    'name' => (string) $animal->name,
                    'species' => (string) ($animal->species ?? ''),
                    'breed_size_category' => (string) ($animal->breed_size_category ?? ''),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function isDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        try {
            Carbon::createFromFormat('Y-m-d', $value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function authorizePermission(string $permission): void
    {
        $user = auth()->user();

        abort_unless($user && $user->hasPermission($permission), 403);
    }
}

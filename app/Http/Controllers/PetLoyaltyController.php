<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\LoyaltyEarningRule;
use App\Models\LoyaltyLevel;
use App\Models\LoyaltySetting;
use App\Models\PetLoyaltyAccount;
use App\Models\PetLoyaltyTransaction;
use App\Models\Subscription;
use App\Services\PetLoyaltyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/*
|--------------------------------------------------------------------------
| PET LOYALTY PROGRAM (back-office)
|--------------------------------------------------------------------------
|
| index : customers, number of subscriptions, points (per pet)
| show  : one customer, the points and history of each pet
| settings popup: rules, values, levels (Arabic / English)
|
*/

class PetLoyaltyController extends Controller
{
    public function __construct(private PetLoyaltyService $service)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMERS LIST
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): View
    {
        $this->authorizePermission('pet_loyalty.view');

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'level' => (string) $request->input('level', ''),
            'can_use' => (string) $request->input('can_use', ''),
            'sort' => (string) $request->input('sort', 'points'),
        ];

        $settings = LoyaltySetting::current();

        // Totals per customer (all his pets)
        $totals = PetLoyaltyAccount::query()
            ->selectRaw('subscription_id, SUM(balance) AS points, SUM(subscriptions_total) AS subscriptions_count, COUNT(*) AS pets_count, SUM(lifetime_earned) AS earned, MAX(balance) AS best_balance')
            ->whereNotNull('subscription_id')
            ->groupBy('subscription_id');

        $query = Subscription::query()
            ->joinSub($totals, 'lt', 'lt.subscription_id', '=', 'subscriptions.id')
            ->select('subscriptions.*', 'lt.points', 'lt.subscriptions_count', 'lt.pets_count', 'lt.earned', 'lt.best_balance');

        if ($filters['q'] !== '') {
            $search = '%' . $filters['q'] . '%';

            $query->where(function ($q) use ($search) {
                $q->where('subscriptions.subscriber_first_name', 'like', $search)
                    ->orWhere('subscriptions.subscriber_last_name', 'like', $search)
                    ->orWhere('subscriptions.subscriber_phone', 'like', $search)
                    ->orWhere('subscriptions.code', 'like', $search)
                    ->orWhereRaw("CONCAT(COALESCE(subscriptions.subscriber_first_name, ''), ' ', COALESCE(subscriptions.subscriber_last_name, '')) LIKE ?", [$search])
                    ->orWhereIn('subscriptions.id', Animal::query()->select('subscription_id')->where('name', 'like', $search));
            });
        }

        if ($filters['level'] !== '') {
            $query->whereIn('subscriptions.id', PetLoyaltyAccount::query()->select('subscription_id')->where('level_id', (int) $filters['level']));
        }

        // A pet of the customer has enough points to use them
        if ($filters['can_use'] === '1') {
            $query->where('lt.best_balance', '>=', $settings->min_points_to_redeem);
        }

        match ($filters['sort']) {
            'subscriptions' => $query->orderByDesc('lt.subscriptions_count'),
            'earned' => $query->orderByDesc('lt.earned'),
            'name' => $query->orderBy('subscriptions.subscriber_first_name')->orderBy('subscriptions.subscriber_last_name'),
            default => $query->orderByDesc('lt.points'),
        };

        $customers = $query->orderByDesc('subscriptions.id')->paginate(25)->withQueryString();

        $accountsByCustomer = PetLoyaltyAccount::query()
            ->with(['animal', 'level'])
            ->whereIn('subscription_id', $customers->pluck('id'))
            ->orderByDesc('balance')
            ->get()
            ->groupBy('subscription_id');

        $stats = [
            'pets' => PetLoyaltyAccount::query()->count(),
            'customers' => PetLoyaltyAccount::query()->whereNotNull('subscription_id')->distinct()->count('subscription_id'),
            'points' => (int) PetLoyaltyAccount::query()->sum('balance'),
            'redeemed' => (int) PetLoyaltyAccount::query()->sum('lifetime_redeemed'),
            'can_use' => PetLoyaltyAccount::query()->where('balance', '>=', $settings->min_points_to_redeem)->count(),
            'expiring' => PetLoyaltyAccount::query()->where('balance', '>', 0)->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', now()->addDays(7))->count(),
        ];

        return view('pet-loyalty.index', [
            'customers' => $customers,
            'accountsByCustomer' => $accountsByCustomer,
            'filters' => $filters,
            'stats' => $stats,
            'settings' => $settings,
            'rules' => LoyaltyEarningRule::query()->orderByRaw("FIELD(kind, 'trial', 'regular', 'premium')")->orderBy('from_number')->get(),
            'levels' => LoyaltyLevel::query()->orderBy('min_points')->orderBy('sort_order')->get(),
            'canSettings' => $this->can('pet_loyalty.settings'),
            'canRecalculate' => $this->can('pet_loyalty.recalculate'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ONE CUSTOMER: points + history of each pet
    |--------------------------------------------------------------------------
    */

    public function show(Subscription $subscription): View
    {
        $this->authorizePermission('pet_loyalty.view');

        // Always fresh on this page
        $this->service->rebuildSubscription($subscription->id);

        $accounts = PetLoyaltyAccount::query()
            ->with(['animal', 'level', 'transactions.purchase', 'transactions.creator'])
            ->where('subscription_id', $subscription->id)
            ->get()
            ->sortBy(fn (PetLoyaltyAccount $account) => $account->animal?->name)
            ->values();

        $levels = LoyaltyLevel::query()->where('is_active', true)->orderBy('min_points')->get();

        $rules = LoyaltyEarningRule::query()->orderBy('from_number')->get()->groupBy('kind');

        return view('pet-loyalty.show', [
            'subscription' => $subscription,
            'accounts' => $accounts,
            'levels' => $levels,
            'rules' => $rules,
            'settings' => LoyaltySetting::current(),
            'canAdjust' => $this->can('pet_loyalty.adjust'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SETTINGS (popup): values + earning rules + levels
    |--------------------------------------------------------------------------
    */

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorizePermission('pet_loyalty.settings');

        $data = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'point_value' => ['required', 'numeric', 'min:0', 'max:100'],
            'currency' => ['required', 'string', 'max:8'],
            'min_points_to_redeem' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_points_per_purchase' => ['required', 'integer', 'min:1', 'max:100000'],
            'trial_bonus_days' => ['required', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'days_per_meal' => ['required', 'numeric', 'min:0.1', 'max:30'],
            'reset_counters_on_expiry' => ['nullable', 'boolean'],
            'reminders_enabled' => ['nullable', 'boolean'],
            'remind_days_before_end' => ['required', 'integer', 'min:0', 'max:60'],
            'remind_days_before_expiry' => ['required', 'integer', 'min:0', 'max:60'],
            'reservation_hours' => ['required', 'integer', 'min:1', 'max:720'],

            'rules' => ['required', 'array', 'min:1'],
            'rules.*.kind' => ['required', 'in:' . implode(',', array_keys(LoyaltyEarningRule::KINDS))],
            'rules.*.from_number' => ['required', 'integer', 'min:1', 'max:1000'],
            'rules.*.to_number' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'rules.*.points' => ['required', 'integer', 'min:0', 'max:100000'],

            'levels' => ['nullable', 'array'],
            'levels.*.id' => ['nullable', 'integer'],
            'levels.*.name_en' => ['required', 'string', 'max:100'],
            'levels.*.name_ar' => ['required', 'string', 'max:100'],
            'levels.*.description_en' => ['nullable', 'string', 'max:500'],
            'levels.*.description_ar' => ['nullable', 'string', 'max:500'],
            'levels.*.min_points' => ['required', 'integer', 'min:0', 'max:1000000'],
            'levels.*.color' => ['required', 'string', 'max:20'],
            'levels.*.icon' => ['nullable', 'string', 'max:50'],
            'levels.*.is_active' => ['nullable', 'boolean'],
        ]);

        if ((int) $data['min_points_to_redeem'] > (int) $data['max_points_per_purchase']) {
            throw ValidationException::withMessages([
                'min_points_to_redeem' => 'The minimum to use points cannot be higher than the maximum per pack.',
            ]);
        }

        foreach ($data['rules'] as $index => $rule) {
            if (!empty($rule['to_number']) && (int) $rule['to_number'] < (int) $rule['from_number']) {
                throw ValidationException::withMessages([
                    "rules.{$index}.to_number" => 'Rule ' . ($index + 1) . ': "to" must be after "from".',
                ]);
            }
        }

        DB::transaction(function () use ($data) {
            LoyaltySetting::current()->fill([
                'is_enabled' => (bool) ($data['is_enabled'] ?? false),
                'point_value' => $data['point_value'],
                'currency' => strtoupper($data['currency']),
                'min_points_to_redeem' => $data['min_points_to_redeem'],
                'max_points_per_purchase' => $data['max_points_per_purchase'],
                'trial_bonus_days' => $data['trial_bonus_days'],
                'grace_days' => $data['grace_days'],
                'days_per_meal' => $data['days_per_meal'],
                'reset_counters_on_expiry' => (bool) ($data['reset_counters_on_expiry'] ?? false),
                'reminders_enabled' => (bool) ($data['reminders_enabled'] ?? false),
                'remind_days_before_end' => $data['remind_days_before_end'],
                'remind_days_before_expiry' => $data['remind_days_before_expiry'],
                'reservation_hours' => $data['reservation_hours'],
            ])->save();

            // Rules: replaced by the list of the popup
            LoyaltyEarningRule::query()->delete();

            foreach ($data['rules'] as $rule) {
                LoyaltyEarningRule::query()->create([
                    'kind' => $rule['kind'],
                    'from_number' => (int) $rule['from_number'],
                    'to_number' => isset($rule['to_number']) && $rule['to_number'] !== '' ? (int) $rule['to_number'] : null,
                    'points' => (int) $rule['points'],
                ]);
            }

            // Levels: updated / added, the removed ones deleted
            $keep = [];

            foreach (array_values($data['levels'] ?? []) as $index => $level) {
                $values = [
                    'name_en' => $level['name_en'],
                    'name_ar' => $level['name_ar'],
                    'description_en' => $level['description_en'] ?? null,
                    'description_ar' => $level['description_ar'] ?? null,
                    'min_points' => (int) $level['min_points'],
                    'color' => $level['color'],
                    'icon' => ($level['icon'] ?? '') ?: 'fa-medal',
                    'sort_order' => $index + 1,
                    'is_active' => (bool) ($level['is_active'] ?? false),
                ];

                $model = !empty($level['id']) ? LoyaltyLevel::query()->find((int) $level['id']) : null;

                if ($model) {
                    $model->fill($values)->save();
                } else {
                    $model = LoyaltyLevel::query()->create($values);
                }

                $keep[] = $model->id;
            }

            LoyaltyLevel::query()->whereNotIn('id', $keep ?: [0])->delete();
        });

        LoyaltySetting::forget();

        // New rules: all the pets recalculated
        $count = $this->service->rebuildAll();

        return back()->with('success', "Loyalty settings saved. {$count} pet(s) recalculated.");
    }

    /*
    |--------------------------------------------------------------------------
    | RECALCULATE ALL
    |--------------------------------------------------------------------------
    */

    public function recalculate(): RedirectResponse
    {
        $this->authorizePermission('pet_loyalty.recalculate');

        $count = $this->service->rebuildAll();

        return back()->with('success', "{$count} pet(s) recalculated.");
    }

    /*
    |--------------------------------------------------------------------------
    | MANUAL ADJUSTMENT (bonus / correction)
    |--------------------------------------------------------------------------
    */

    public function adjust(Request $request, Animal $animal): RedirectResponse
    {
        $this->authorizePermission('pet_loyalty.adjust');

        $data = $request->validate([
            'direction' => ['required', 'in:add,remove'],
            'points' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $points = (int) $data['points'] * ($data['direction'] === 'remove' ? -1 : 1);

        $this->service->adjust($animal, $points, $data['note'], auth()->id());

        return back()->with('success', ($points > 0 ? '+' : '') . "{$points} points for {$animal->name}.");
    }

    public function destroyAdjustment(PetLoyaltyTransaction $transaction): RedirectResponse
    {
        $this->authorizePermission('pet_loyalty.adjust');

        // Only manual lines (the others are calculated)
        abort_if($transaction->is_auto, 403, 'Calculated lines cannot be deleted.');

        $animalId = (int) $transaction->animal_id;
        $transaction->delete();

        $this->service->rebuildPet($animalId);

        return back()->with('success', 'Adjustment deleted.');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    private function can(string $permission): bool
    {
        $user = auth()->user();

        return $user && method_exists($user, 'hasPermission') && $user->hasPermission($permission);
    }

    private function authorizePermission(string $permission): void
    {
        abort_unless($this->can($permission), 403, 'You do not have permission to access the loyalty program.');
    }
}

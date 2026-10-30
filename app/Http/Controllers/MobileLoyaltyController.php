<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\CustomerAccount;
use App\Models\LoyaltyEarningRule;
use App\Models\LoyaltyLevel;
use App\Models\LoyaltySetting;
use App\Models\PetLoyaltyAccount;
use App\Models\PetLoyaltyTransaction;
use App\Services\PetLoyaltyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| MOBILE APP: LOYALTY POINTS OF MY PETS
|--------------------------------------------------------------------------
|
| POST /api/mobile/loyalty   { access_token, lang }
|
| For each pet: points, points usable now (+ discount), level, next level,
| date the points are lost, points of the next pack, last history lines.
|
*/

class MobileLoyaltyController extends Controller
{
    public function __construct(private PetLoyaltyService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $lang = $request->input('lang') === 'ar' ? 'ar' : 'en';
        $settings = LoyaltySetting::current();

        $animals = $customer->subscription_id
            ? Animal::query()->where('subscription_id', $customer->subscription_id)->orderBy('name')->get()
            : collect();

        /*
         * Points are recalculated automatically (purchase / delivery saved,
         * every night). Here only the pets never calculated, or not
         * recalculated for 6 hours: the app calls this page often, it must
         * stay light for the database.
         */
        $fresh = PetLoyaltyAccount::query()
            ->whereIn('animal_id', $animals->pluck('id'))
            ->where('rebuilt_at', '>=', now()->subHours(6))
            ->pluck('animal_id')
            ->all();

        foreach ($animals as $animal) {
            if (!in_array($animal->id, $fresh)) {
                $this->service->rebuildPetSafely($animal->id);
            }
        }

        $accounts = PetLoyaltyAccount::query()
            ->with('level')
            ->whereIn('animal_id', $animals->pluck('id'))
            ->get()
            ->keyBy('animal_id');

        $levels = LoyaltyLevel::query()->where('is_active', true)->orderBy('min_points')->get();
        $rules = LoyaltyEarningRule::query()->orderBy('from_number')->get()->groupBy('kind');

        $pets = $animals->map(function (Animal $animal) use ($accounts, $levels, $rules, $settings, $lang) {
            /** @var PetLoyaltyAccount|null $account */
            $account = $accounts->get($animal->id);

            $balance = (int) ($account?->balance ?? 0);
            $earned = (int) ($account?->lifetime_earned ?? 0);
            $usable = $account ? $account->usablePoints($settings) : 0;
            $level = $account?->level;
            $nextLevel = $levels->first(fn (LoyaltyLevel $l) => $l->min_points > $earned);

            $regularNext = (int) ($account?->regular_count ?? 0) + 1;
            $premiumNext = (int) ($account?->premium_count ?? 0) + 1;

            $history = PetLoyaltyTransaction::query()
                ->where('animal_id', $animal->id)
                ->orderByDesc('happened_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (PetLoyaltyTransaction $row) => [
                    'id' => $row->id,
                    'type' => $row->type,
                    'label' => $row->typeLabel($lang),
                    'points' => $row->points,
                    'balance_after' => $row->balance_after,
                    'date' => $row->happened_at?->toIso8601String(),
                ])
                ->values();

            return [
                'pet' => [
                    'id' => $animal->id,
                    'name' => $animal->name,
                    'species' => $animal->species,
                ],

                'balance' => $balance,
                'lifetime_earned' => $earned,
                'usable_points' => $usable,
                'usable_discount' => $settings->valueOf($usable),
                'missing_points' => max(0, $settings->min_points_to_redeem - $balance),

                'expires_on' => $balance > 0 ? $account?->expires_on?->format('Y-m-d') : null,
                'days_left' => $balance > 0 && $account?->expires_on
                    ? (int) Carbon::today()->diffInDays($account->expires_on, false)
                    : null,

                // Test pack points kept only if subscribed by this day
                'trial_status' => $account?->trial_status ?? 'none',
                'trial_deadline' => $account?->trial_status === 'pending' ? $account->trial_deadline?->format('Y-m-d') : null,

                'next_points' => [
                    'regular' => $this->pointsFor($rules, 'regular', $regularNext),
                    'premium' => $this->pointsFor($rules, 'premium', $premiumNext),
                ],

                'level' => $level ? $this->formatLevel($level, $lang) : null,
                'next_level' => $nextLevel ? $this->formatLevel($nextLevel, $lang) + [
                    'points_needed' => max(0, $nextLevel->min_points - $earned),
                ] : null,

                'history' => $history,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'program' => [
                'enabled' => $settings->is_enabled,
                'point_value' => (float) $settings->point_value,
                'currency' => $settings->currency,
                'min_points_to_use' => $settings->min_points_to_redeem,
                'max_points_per_pack' => $settings->max_points_per_purchase,
                'trial_bonus_days' => $settings->trial_bonus_days,
                'grace_days' => $settings->grace_days,
            ],
            'levels' => $levels->map(fn (LoyaltyLevel $level) => $this->formatLevel($level, $lang))->values(),
            // How to earn points (rules of the settings)
            'rules' => LoyaltyEarningRule::query()->orderByRaw("FIELD(kind, 'trial', 'regular', 'premium')")->orderBy('from_number')->get()
                ->map(fn (LoyaltyEarningRule $rule) => [
                    'kind' => $rule->kind,
                    'from' => $rule->from_number,
                    'to' => $rule->to_number,
                    'points' => $rule->points,
                ])->values(),
            'total_points' => $pets->sum('balance'),
            'pets' => $pets,
        ]);
    }

    private function formatLevel(LoyaltyLevel $level, string $lang): array
    {
        return [
            'id' => $level->id,
            'name' => $level->name($lang),
            'name_en' => $level->name_en,
            'name_ar' => $level->name_ar,
            'description' => $level->description($lang),
            'min_points' => $level->min_points,
            'color' => $level->color,
            'icon' => $level->iconClass(),
        ];
    }

    private function pointsFor($rules, string $kind, int $number): int
    {
        $rule = ($rules->get($kind) ?? collect())->first(fn (LoyaltyEarningRule $rule) => $rule->matches($number));

        return $rule ? (int) $rule->points : 0;
    }

    /** Same authentication as the other mobile controllers. */
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
}

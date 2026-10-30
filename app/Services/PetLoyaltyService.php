<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\LoyaltyEarningRule;
use App\Models\LoyaltyLevel;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltySetting;
use App\Models\PetLoyaltyAccount;
use App\Models\PetLoyaltyTransaction;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/*
|--------------------------------------------------------------------------
| PET LOYALTY PROGRAM
|--------------------------------------------------------------------------
|
| The points belong to the PET. They are always RECALCULATED from the
| history (purchases + points used + manual adjustments), in date order:
| a cancelled / refunded purchase, a changed delivery date or new rules
| give the right balance without any manual correction.
|
| Rules (editable in the settings):
|  - Test pack (TP-): +15, once per pet. Kept only if a subscription is
|    bought within 14 days after the end of the test pack, otherwise lost.
|  - Subscription (REG-) #1-3: +35, #4-6: +45, #7 and after: +55
|  - Premium (PREMIUM- / CUSTOM-) #1-5: +45, #6 and after: +55
|  - A pack lasts (meals x days per meal) days from its first delivery.
|    No new subscription within 30 days after the end of the last pack:
|    all the points are lost (and the counters start again at #1).
|  - Points can be used from 50 points, 150 at most on one pack, on the
|    NEXT pack (never on the pack that earned them).
|
*/

class PetLoyaltyService
{
    /** Purchases that give points (paid). */
    public const EARNING_STATUSES = ['paid', 'active', 'completed', 'expired'];

    /** Kind of a purchase: trial / regular / premium (null = no points). */
    public static function kindOf(Purchase $purchase): ?string
    {
        $code = strtoupper(trim((string) $purchase->product_code));

        // PREMIUM-... pack (its product can be changed to a CUSTOM-... one)
        if ($purchase->premium_status !== null || str_starts_with($code, 'PREMIUM-') || str_starts_with($code, 'CUSTOM-')) {
            return 'premium';
        }

        if (str_starts_with($code, 'TP-')) {
            return 'trial';
        }

        if (str_starts_with($code, 'REG-')) {
            return 'regular';
        }

        return null;
    }

    /** Date of the purchase in the history (payment). */
    public static function eventTime(Purchase $purchase): Carbon
    {
        return Carbon::parse($purchase->paid_at ?? $purchase->purchased_at ?? $purchase->created_at ?? now());
    }

    /*
    |--------------------------------------------------------------------------
    | RECALCULATION
    |--------------------------------------------------------------------------
    */

    /** Recalculates all the pets with points activity. Returns the number of pets. */
    public function rebuildAll(): int
    {
        $ids = Purchase::query()->whereNotNull('animal_id')->distinct()->pluck('animal_id')
            ->merge(PetLoyaltyAccount::query()->pluck('animal_id'))
            ->merge(PetLoyaltyTransaction::query()->where('is_auto', false)->distinct()->pluck('animal_id'))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        foreach ($ids as $id) {
            $this->rebuildPetSafely($id);
        }

        return $ids->count();
    }

    /** Recalculates the pets of a customer (subscription). */
    public function rebuildSubscription(int $subscriptionId): void
    {
        Animal::query()->where('subscription_id', $subscriptionId)->pluck('id')
            ->merge(PetLoyaltyAccount::query()->where('subscription_id', $subscriptionId)->pluck('animal_id'))
            ->unique()
            ->each(fn ($id) => $this->rebuildPetSafely((int) $id));
    }

    /** Never breaks the caller (purchase saved, page opened...). */
    public function rebuildPetSafely(?int $animalId): void
    {
        if (!$animalId) {
            return;
        }

        try {
            $this->rebuildPet($animalId);
        } catch (Throwable $exception) {
            Log::warning('Pet loyalty rebuild failed', ['animal_id' => $animalId, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Replays the whole history of a pet and saves its points.
     */
    public function rebuildPet(int $animalId): ?PetLoyaltyAccount
    {
        $animal = Animal::query()->find($animalId);

        if (!$animal) {
            DB::transaction(function () use ($animalId) {
                PetLoyaltyTransaction::query()->where('animal_id', $animalId)->delete();
                PetLoyaltyAccount::query()->where('animal_id', $animalId)->delete();
            });

            return null;
        }

        $settings = LoyaltySetting::current();
        $rules = LoyaltyEarningRule::query()->orderBy('from_number')->get()->groupBy('kind');

        /*
         * Events in date order: paid purchases + manual adjustments
         */
        $purchases = Purchase::query()
            ->with(['deliveries' => fn ($query) => $query->where('status', '!=', 'cancelled')])
            ->where('animal_id', $animalId)
            ->whereIn('status', self::EARNING_STATUSES)
            ->get()
            ->filter(fn (Purchase $purchase) => self::kindOf($purchase) !== null);

        $redemptions = LoyaltyRedemption::query()->where('animal_id', $animalId)->get()->keyBy('purchase_id');

        $manual = PetLoyaltyTransaction::query()
            ->where('animal_id', $animalId)
            ->where('is_auto', false)
            ->orderBy('happened_at')
            ->orderBy('id')
            ->get();

        $events = collect();

        foreach ($purchases as $purchase) {
            $events->push(['at' => self::eventTime($purchase), 'order' => 1, 'purchase' => $purchase]);
        }

        foreach ($manual as $row) {
            $events->push(['at' => Carbon::parse($row->happened_at), 'order' => 2, 'manual' => $row]);
        }

        $events = $events->sort(fn ($a, $b) => [$a['at']->getTimestamp(), $a['order']] <=> [$b['at']->getTimestamp(), $b['order']])->values();

        /*
         * Replay
         */
        $state = [
            'balance' => 0,
            'earned' => 0,
            'redeemed' => 0,
            'total' => 0,
            'regular' => 0,
            'premium' => 0,
            'trial_earned' => false,
            'trial_status' => 'none',
            'trial_points' => 0,
            'trial_deadline' => null, // Carbon (last day to keep the test pack points)
            'deadline' => null,       // Carbon (last day to buy a subscription)
            'last_event' => null,
        ];

        $rows = [];
        $manualBalances = [];

        foreach ($events as $event) {
            $this->applyExpiries($state, $rows, $event['at'], $settings, $animal);
            $state['last_event'] = $event['at'];

            if (isset($event['manual'])) {
                /** @var PetLoyaltyTransaction $row */
                $row = $event['manual'];

                // Never below 0
                $points = max((int) $row->points, -$state['balance']);
                $state['balance'] += $points;

                if ($points > 0) {
                    $state['earned'] += $points;
                }

                $manualBalances[$row->id] = $state['balance'];
                continue;
            }

            /** @var Purchase $purchase */
            $purchase = $event['purchase'];
            $kind = self::kindOf($purchase);

            // 1. Points used on this pack (from the points earned BEFORE it)
            $redemption = $redemptions->get($purchase->id);

            if ($redemption) {
                $used = min((int) $redemption->points, $state['balance']);

                if ($used > 0) {
                    $state['balance'] -= $used;
                    $state['redeemed'] += $used;
                    $rows[] = $this->row($animal, $purchase, 'redeem', -$used, $state['balance'], null, $event['at'],
                        'Discount ' . number_format($settings->valueOf($used), 3) . ' ' . $settings->currency);
                }
            }

            // 2. Points earned by this pack
            if ($kind === 'trial') {
                if ($state['trial_earned']) {
                    continue; // test pack points: once per pet
                }

                $state['trial_earned'] = true;
                $points = $this->pointsFor($rules, 'trial', 1);

                if ($points > 0) {
                    $state['balance'] += $points;
                    $state['earned'] += $points;
                    $state['trial_points'] = $points;
                    $state['trial_status'] = 'pending';
                    $state['trial_deadline'] = $this->packEnd($purchase, $settings)->addDays($settings->trial_bonus_days);

                    $rows[] = $this->row($animal, $purchase, 'trial', $points, $state['balance'], 1, $event['at'],
                        'Kept if a subscription is bought by ' . $state['trial_deadline']->format('Y-m-d'));
                }

                continue;
            }

            // Subscription bought in time: the test pack points are kept
            if ($state['trial_status'] === 'pending'
                && $state['trial_deadline']
                && $event['at']->copy()->startOfDay()->lte($state['trial_deadline'])) {
                $state['trial_status'] = 'confirmed';
            }

            $number = $kind === 'premium' ? ++$state['premium'] : ++$state['regular'];
            $state['total']++;

            $points = $this->pointsFor($rules, $kind, $number);

            $state['balance'] += $points;
            $state['earned'] += $points;

            $end = $this->packEnd($purchase, $settings)->addDays($settings->grace_days);
            $state['deadline'] = $state['deadline'] && $state['deadline']->gt($end) ? $state['deadline'] : $end;

            $rows[] = $this->row($animal, $purchase, $kind, $points, $state['balance'], $number, $event['at'],
                $purchase->product_code);
        }

        // Up to today
        $this->applyExpiries($state, $rows, now(), $settings, $animal);

        /*
         * Save
         */
        $level = LoyaltyLevel::query()
            ->where('is_active', true)
            ->where('min_points', '<=', $state['earned'])
            ->orderByDesc('min_points')
            ->first();

        return DB::transaction(function () use ($animal, $rows, $manualBalances, $state, $level) {
            PetLoyaltyTransaction::query()->where('animal_id', $animal->id)->where('is_auto', true)->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                PetLoyaltyTransaction::query()->insert($chunk);
            }

            foreach ($manualBalances as $id => $balance) {
                PetLoyaltyTransaction::query()->whereKey($id)->update([
                    'balance_after' => $balance,
                    'subscription_id' => $animal->subscription_id,
                ]);
            }

            return PetLoyaltyAccount::query()->updateOrCreate(
                ['animal_id' => $animal->id],
                [
                    'subscription_id' => $animal->subscription_id,
                    'balance' => $state['balance'],
                    'lifetime_earned' => $state['earned'],
                    'lifetime_redeemed' => $state['redeemed'],
                    'subscriptions_total' => $this->subscriptionsTotal($animal->id),
                    'regular_count' => $state['regular'],
                    'premium_count' => $state['premium'],
                    'trial_status' => $state['trial_status'],
                    'trial_deadline' => $state['trial_deadline']?->format('Y-m-d'),
                    'expires_on' => $state['balance'] > 0 ? $state['deadline']?->format('Y-m-d') : null,
                    'level_id' => $level?->id,
                    'last_event_at' => $state['last_event'],
                    'rebuilt_at' => now(),
                ]
            );
        });
    }

    /**
     * Points lost before $until:
     *  - test pack points: no subscription by the trial deadline
     *  - all points: no subscription by the deadline (end of last pack + grace)
     */
    private function applyExpiries(array &$state, array &$rows, Carbon $until, LoyaltySetting $settings, Animal $animal): void
    {
        $moments = [];

        if ($state['trial_status'] === 'pending' && $state['trial_deadline']) {
            $moments['trial'] = $state['trial_deadline']->copy()->addDay()->startOfDay();
        }

        if ($state['deadline']) {
            $moments['all'] = $state['deadline']->copy()->addDay()->startOfDay();
        }

        asort($moments);

        foreach ($moments as $what => $moment) {
            if ($moment->gt($until)) {
                continue;
            }

            if ($what === 'trial' && $state['trial_status'] === 'pending') {
                $lost = min($state['trial_points'], $state['balance']);
                $state['trial_status'] = 'lost';

                if ($lost > 0) {
                    $state['balance'] -= $lost;
                    $rows[] = $this->row($animal, null, 'trial_lost', -$lost, $state['balance'], null, $moment,
                        'No subscription within ' . $settings->trial_bonus_days . ' days after the test pack');
                }
            }

            if ($what === 'all' && $state['deadline']) {
                if ($state['balance'] > 0) {
                    $lost = $state['balance'];
                    $state['balance'] = 0;
                    $rows[] = $this->row($animal, null, 'expired', -$lost, 0, null, $moment,
                        'No new subscription within ' . $settings->grace_days . ' days after the end of the last pack');
                }

                if ($settings->reset_counters_on_expiry) {
                    $state['regular'] = 0;
                    $state['premium'] = 0;
                }

                if ($state['trial_status'] === 'pending') {
                    $state['trial_status'] = 'lost';
                }

                $state['deadline'] = null;
            }
        }
    }

    /** Points of the Nth purchase of a kind (rules of the settings). */
    private function pointsFor(Collection $rules, string $kind, int $number): int
    {
        /** @var LoyaltyEarningRule|null $rule */
        $rule = ($rules->get($kind) ?? collect())->first(fn (LoyaltyEarningRule $rule) => $rule->matches($number));

        return $rule ? (int) $rule->points : 0;
    }

    /**
     * End of a pack: first delivery (or payment if not planned yet)
     * + meals x days per meal.
     */
    public function packEnd(Purchase $purchase, ?LoyaltySetting $settings = null): Carbon
    {
        $settings ??= LoyaltySetting::current();

        $first = $purchase->relationLoaded('deliveries')
            ? $purchase->deliveries->where('status', '!=', 'cancelled')->min('delivery_date')
            : $purchase->deliveries()->where('status', '!=', 'cancelled')->min('delivery_date');

        $start = $first ? Carbon::parse($first) : self::eventTime($purchase);
        $days = (int) ceil(max(1, (int) $purchase->total_recipes) * max(0.01, (float) $settings->days_per_meal));

        return $start->copy()->startOfDay()->addDays($days);
    }

    private function subscriptionsTotal(int $animalId): int
    {
        return Purchase::query()
            ->where('animal_id', $animalId)
            ->whereIn('status', self::EARNING_STATUSES)
            ->get(['id', 'product_code', 'premium_status'])
            ->filter(fn (Purchase $purchase) => in_array(self::kindOf($purchase), ['regular', 'premium'], true))
            ->count();
    }

    private function row(Animal $animal, ?Purchase $purchase, string $type, int $points, int $balance, ?int $number, Carbon $at, ?string $note): array
    {
        return [
            'animal_id' => $animal->id,
            'subscription_id' => $animal->subscription_id,
            'purchase_id' => $purchase?->id,
            'type' => $type,
            'points' => $points,
            'balance_after' => $balance,
            'number' => $number,
            'is_auto' => true,
            'note' => $note ? mb_substr($note, 0, 500) : null,
            'happened_at' => $at->format('Y-m-d H:i:s'),
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | MANUAL ADJUSTMENT (back-office)
    |--------------------------------------------------------------------------
    */

    public function adjust(Animal $animal, int $points, string $note, ?int $userId): PetLoyaltyTransaction
    {
        $row = PetLoyaltyTransaction::query()->create([
            'animal_id' => $animal->id,
            'subscription_id' => $animal->subscription_id,
            'purchase_id' => null,
            'type' => 'adjust',
            'points' => $points,
            'balance_after' => 0,
            'number' => null,
            'is_auto' => false,
            'note' => mb_substr($note, 0, 500),
            'happened_at' => now(),
            'created_by' => $userId,
        ]);

        $this->rebuildPet($animal->id);

        return $row;
    }

    /*
    |--------------------------------------------------------------------------
    | USE POINTS ON A PACK (checkout - used later by the app)
    |--------------------------------------------------------------------------
    */

    /**
     * Points of the pet already used on orders NOT paid yet (kept for
     * "reservation_hours"): they cannot be used a second time.
     */
    public function reservedPoints(int $animalId, ?int $exceptPurchaseId = null): int
    {
        $hours = max(1, (int) LoyaltySetting::current()->reservation_hours);

        return (int) LoyaltyRedemption::query()
            ->join('purchases', 'purchases.id', '=', 'loyalty_redemptions.purchase_id')
            ->where('loyalty_redemptions.animal_id', $animalId)
            ->where('purchases.status', 'pending')
            ->where('loyalty_redemptions.created_at', '>=', now()->subHours($hours))
            ->when($exceptPurchaseId, fn ($q) => $q->where('loyalty_redemptions.purchase_id', '!=', $exceptPurchaseId))
            ->sum('loyalty_redemptions.points');
    }

    /** What the pet can use now: points + discount (points reserved on unpaid orders excluded). */
    public function preview(int $animalId, ?float $packPrice = null, ?int $exceptPurchaseId = null): array
    {
        $settings = LoyaltySetting::current();
        $account = PetLoyaltyAccount::query()->where('animal_id', $animalId)->first();

        $balance = (int) ($account?->balance ?? 0);
        $reserved = $account ? $this->reservedPoints($animalId, $exceptPurchaseId) : 0;
        $available = max(0, $balance - $reserved);

        $usable = $settings->is_enabled && $available >= $settings->min_points_to_redeem
            ? min($available, $settings->max_points_per_purchase)
            : 0;

        // Never more than the price of the pack
        if ($packPrice !== null && $usable > 0 && (float) $settings->point_value > 0) {
            $usable = min($usable, (int) floor(round($packPrice / (float) $settings->point_value, 6)));

            // Below the minimum after the cap: not usable on this pack
            if ($usable < $settings->min_points_to_redeem) {
                $usable = 0;
            }
        }

        return [
            'enabled' => $settings->is_enabled,
            'balance' => $balance,
            'reserved' => $reserved,
            'available' => $available,
            'usable_points' => $usable,
            'discount' => $settings->valueOf($usable),
            'currency' => $settings->currency,
            'point_value' => (float) $settings->point_value,
            'min_points' => $settings->min_points_to_redeem,
            'max_points' => $settings->max_points_per_purchase,
            'missing_points' => max(0, $settings->min_points_to_redeem - $available),
            'expires_on' => $balance > 0 ? $account?->expires_on?->format('Y-m-d') : null,
            'level' => $account?->level ? [
                'name_en' => $account->level->name_en,
                'name_ar' => $account->level->name_ar,
                'color' => $account->level->color,
                'icon' => $account->level->iconClass(),
            ] : null,
        ];
    }

    /**
     * Checkout: uses the points of the pet on a NEW purchase (not paid yet).
     * The price of the purchase is lowered here. Returns the points used (0 = none).
     */
    public function applyToNewPurchase(Purchase $purchase): int
    {
        if (!$purchase->animal_id) {
            return 0;
        }

        $this->rebuildPetSafely((int) $purchase->animal_id);

        $preview = $this->preview((int) $purchase->animal_id, (float) $purchase->total_amount, $purchase->id);
        $points = (int) $preview['usable_points'];

        if ($points <= 0) {
            return 0;
        }

        $amount = min((float) $purchase->total_amount, (float) $preview['discount']);

        DB::transaction(function () use ($purchase, $points, $amount, $preview) {
            LoyaltyRedemption::query()->updateOrCreate(
                ['purchase_id' => $purchase->id],
                [
                    'animal_id' => $purchase->animal_id,
                    'points' => $points,
                    'amount' => $amount,
                    'note' => 'Used at checkout (mobile app)',
                ]
            );

            $purchase->forceFill([
                'discount_amount' => round((float) $purchase->discount_amount + $amount, 3),
                'total_amount' => max(0, round((float) $purchase->total_amount - $amount, 3)),
                'admin_note' => trim(($purchase->admin_note ? $purchase->admin_note . "\n" : '')
                    . "Loyalty points: -{$points} pts (-" . number_format($amount, 3) . ' ' . $preview['currency'] . ').'),
            ])->save();
        });

        return $points;
    }

    /** Points used on a purchase (0 = none). */
    public function pointsUsedOn(int $purchaseId): array
    {
        $redemption = LoyaltyRedemption::query()->where('purchase_id', $purchaseId)->first();

        return [
            'points' => (int) ($redemption?->points ?? 0),
            'amount' => (float) ($redemption?->amount ?? 0),
        ];
    }

    /** Points earned by a purchase (after its payment). */
    public function pointsEarnedBy(int $purchaseId): int
    {
        return (int) PetLoyaltyTransaction::query()
            ->where('purchase_id', $purchaseId)
            ->whereIn('type', ['trial', 'regular', 'premium'])
            ->sum('points');
    }

    /** Last paid subscription pack of the pet (reminders). */
    public function lastSubscriptionPurchase(int $animalId): ?Purchase
    {
        return Purchase::query()
            ->with(['deliveries' => fn ($query) => $query->where('status', '!=', 'cancelled')])
            ->where('animal_id', $animalId)
            ->whereIn('status', self::EARNING_STATUSES)
            ->get()
            ->filter(fn (Purchase $purchase) => in_array(self::kindOf($purchase), ['regular', 'premium'], true))
            ->sortByDesc(fn (Purchase $purchase) => self::eventTime($purchase)->getTimestamp())
            ->first();
    }

    /**
     * Uses points of the pet of the purchase (discount). The price of the
     * purchase is changed by the caller (checkout).
     */
    public function redeem(Purchase $purchase, int $points, ?int $userId = null): LoyaltyRedemption
    {
        if (!$purchase->animal_id) {
            throw ValidationException::withMessages(['points' => 'This purchase has no pet.']);
        }

        $this->rebuildPet((int) $purchase->animal_id);

        $preview = $this->preview((int) $purchase->animal_id);

        if ($points < $preview['min_points'] || $points > $preview['usable_points']) {
            throw ValidationException::withMessages([
                'points' => $preview['usable_points'] > 0
                    ? "Between {$preview['min_points']} and {$preview['usable_points']} points can be used."
                    : "At least {$preview['min_points']} points are needed.",
            ]);
        }

        $settings = LoyaltySetting::current();

        $redemption = LoyaltyRedemption::query()->updateOrCreate(
            ['purchase_id' => $purchase->id],
            [
                'animal_id' => $purchase->animal_id,
                'points' => $points,
                'amount' => $settings->valueOf($points),
                'created_by' => $userId,
            ]
        );

        $this->rebuildPet((int) $purchase->animal_id);

        return $redemption;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Points of one pet (summary, recalculated by PetLoyaltyService).
 */
class PetLoyaltyAccount extends Model
{
    protected $table = 'pet_loyalty_accounts';

    public const TRIAL_STATUSES = [
        'none' => ['label' => 'No test pack', 'color' => 'secondary'],
        'pending' => ['label' => 'Test pack points kept until', 'color' => 'warning'],
        'confirmed' => ['label' => 'Test pack points kept', 'color' => 'success'],
        'lost' => ['label' => 'Test pack points lost', 'color' => 'danger'],
    ];

    protected $fillable = [
        'animal_id',
        'subscription_id',
        'balance',
        'lifetime_earned',
        'lifetime_redeemed',
        'subscriptions_total',
        'regular_count',
        'premium_count',
        'trial_status',
        'trial_deadline',
        'expires_on',
        'level_id',
        'last_event_at',
        'rebuilt_at',
    ];

    protected $casts = [
        'balance' => 'integer',
        'lifetime_earned' => 'integer',
        'lifetime_redeemed' => 'integer',
        'subscriptions_total' => 'integer',
        'regular_count' => 'integer',
        'premium_count' => 'integer',
        'trial_deadline' => 'date',
        'expires_on' => 'date',
        'last_event_at' => 'datetime',
        'rebuilt_at' => 'datetime',
    ];

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(LoyaltyLevel::class, 'level_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PetLoyaltyTransaction::class, 'animal_id', 'animal_id')
            ->orderByDesc('happened_at')
            ->orderByDesc('id');
    }

    /** Points the pet can use now on one pack (0 = not enough points). */
    public function usablePoints(?LoyaltySetting $settings = null): int
    {
        $settings ??= LoyaltySetting::current();

        if (!$settings->is_enabled || $this->balance < $settings->min_points_to_redeem) {
            return 0;
        }

        return min($this->balance, $settings->max_points_per_purchase);
    }
}

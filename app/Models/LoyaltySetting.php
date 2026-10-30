<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Settings of the pet loyalty program (one row, id = 1).
 */
class LoyaltySetting extends Model
{
    protected $table = 'loyalty_settings';

    protected $fillable = [
        'is_enabled',
        'point_value',
        'currency',
        'min_points_to_redeem',
        'max_points_per_purchase',
        'trial_bonus_days',
        'grace_days',
        'days_per_meal',
        'reset_counters_on_expiry',
        'reminders_enabled',
        'remind_days_before_end',
        'remind_days_before_expiry',
        'reservation_hours',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'point_value' => 'decimal:3',
        'min_points_to_redeem' => 'integer',
        'max_points_per_purchase' => 'integer',
        'trial_bonus_days' => 'integer',
        'grace_days' => 'integer',
        'days_per_meal' => 'decimal:2',
        'reset_counters_on_expiry' => 'boolean',
        'reminders_enabled' => 'boolean',
        'remind_days_before_end' => 'integer',
        'remind_days_before_expiry' => 'integer',
        'reservation_hours' => 'integer',
    ];

    public const DEFAULTS = [
        'is_enabled' => true,
        'point_value' => 0.100,
        'currency' => 'KWD',
        'min_points_to_redeem' => 50,
        'max_points_per_purchase' => 150,
        'trial_bonus_days' => 14,
        'grace_days' => 30,
        'days_per_meal' => 1.00,
        'reset_counters_on_expiry' => true,
        'reminders_enabled' => true,
        'remind_days_before_end' => 7,
        'remind_days_before_expiry' => 3,
        'reservation_hours' => 24,
    ];

    private static ?self $cached = null;

    /** The settings row (created with the defaults if missing). */
    public static function current(): self
    {
        if (self::$cached) {
            return self::$cached;
        }

        try {
            $settings = self::query()->find(1) ?? self::query()->create(self::DEFAULTS);

            // Columns of v2 not created yet (pet_loyalty_v2.sql): defaults
            foreach (self::DEFAULTS as $key => $value) {
                if ($settings->getAttribute($key) === null) {
                    $settings->setAttribute($key, $value);
                    $settings->syncOriginalAttribute($key);
                }
            }

            return self::$cached = $settings;
        } catch (Throwable) {
            // Table not created yet: defaults in memory
            return new self(self::DEFAULTS);
        }
    }

    public static function forget(): void
    {
        self::$cached = null;
    }

    /** Value in money of a number of points. */
    public function valueOf(int $points): float
    {
        return round($points * (float) $this->point_value, 3);
    }
}

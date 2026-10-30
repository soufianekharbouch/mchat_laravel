<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Points earned by a pet for its Nth purchase of a kind.
 *   trial   : TP- test pack
 *   regular : REG- subscription pack
 *   premium : PREMIUM- / CUSTOM- pack
 */
class LoyaltyEarningRule extends Model
{
    protected $table = 'loyalty_earning_rules';

    public const KINDS = [
        'trial' => ['label' => 'Test pack (TP-)', 'color' => 'info'],
        'regular' => ['label' => 'Subscription (REG-)', 'color' => 'primary'],
        'premium' => ['label' => 'Premium (PREMIUM-)', 'color' => 'warning'],
    ];

    protected $fillable = [
        'kind',
        'from_number',
        'to_number',
        'points',
    ];

    protected $casts = [
        'from_number' => 'integer',
        'to_number' => 'integer',
        'points' => 'integer',
    ];

    public function matches(int $number): bool
    {
        return $number >= $this->from_number
            && ($this->to_number === null || $number <= $this->to_number);
    }

    public function rangeLabel(): string
    {
        if ($this->to_number === null) {
            return "#{$this->from_number} and after";
        }

        return $this->from_number === $this->to_number
            ? "#{$this->from_number}"
            : "#{$this->from_number} to #{$this->to_number}";
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'creation_date', 'subscriber_first_name', 'subscriber_last_name',
        'subscriber_phone', 'shopify_customer_id', 'subscriber_address',
        'subscriber_note', 'subscriber_province', 'subscriber_zone',
        'subscriber_delivery_slot', 'delivery_days', 'day_recipes',
    ];

    protected $casts = [
        'delivery_days' => 'array',
        'day_recipes' => 'array',
        'creation_date' => 'date',
    ];

    public function customerAccount() { return $this->hasOne(CustomerAccount::class); }
    public function animals() { return $this->hasMany(Animal::class); }

    public function hasActivePremium(?Carbon $date = null): bool
    {
        $date = $date ?: Carbon::today();

        foreach ($this->animals as $animal) {
            if (!$animal->hasActiveSubscription($date)) continue;

            foreach ($animal->mealDates as $meal) {
                if ($meal->recipe && $meal->recipe->is_premium) return true;
            }
        }

        return false;
    }

    public function plannedOrders() { return $this->hasMany(PlannedOrder::class); }

    public function customerServiceReports()
    {
        return $this->hasMany(\App\Models\CustomerServiceReport::class);
    }

    public function loyaltyPointTransactions()
    {
        return $this->hasMany(LoyaltyPointTransaction::class);
    }

    public function getValidLoyaltyPointsAttribute(): int
    {
        return $this->loyaltyPointTransactions()
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', now()->toDateString());
            })
            ->sum('points');
    }
}

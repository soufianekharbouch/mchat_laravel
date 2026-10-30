<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
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

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER ADDRESSES
    |--------------------------------------------------------------------------
    |
    | subscription -> customer_accounts (subscription_id)
    |              -> customer_addresses (customer_account_id)
    |
    | Usage :
    |   $subscription->deliveryAddress           (default address)
    |   $subscription->deliveryAddress?->zone    (Zone model, name / name_ar)
    |   Subscription::with('deliveryAddress.zone.province')->get()
    |
    */

    public function customerAddresses(): HasManyThrough
    {
        return $this->hasManyThrough(
            CustomerAddress::class,
            CustomerAccount::class,
            'subscription_id',      // customer_accounts.subscription_id
            'customer_account_id',  // customer_addresses.customer_account_id
            'id',                   // subscriptions.id
            'id'                    // customer_accounts.id
        );
    }

    public function deliveryAddress(): HasOneThrough
    {
        return $this->hasOneThrough(
            CustomerAddress::class,
            CustomerAccount::class,
            'subscription_id',
            'customer_account_id',
            'id',
            'id'
        )->where('customer_addresses.is_default', true);
    }

    /*
    |--------------------------------------------------------------------------
    | PREMIUM (PREMIUM-... packs)
    |--------------------------------------------------------------------------
    |
    | A customer is premium when one of his pets has a PREMIUM-... pack
    | paid / running (untreated or already treated with his custom
    | product). Premium recipes in the calendar do not count any more.
    |
    | Eager load to avoid 1 query per row:
    |   Subscription::with('premiumPurchases')->get()
    |
    */

    public function premiumPurchases(): HasMany
    {
        return $this->hasMany(Purchase::class)
            ->whereNotNull('premium_status')
            ->whereIn('status', ['paid', 'active']);
    }

    /**
     * Premium packs still covering $date (no end date yet = not started).
     */
    public function activePremiumPurchases(?Carbon $date = null)
    {
        $date = ($date ?: Carbon::today())->copy()->startOfDay();

        try {
            $purchases = $this->relationLoaded('premiumPurchases')
                ? $this->premiumPurchases
                : $this->premiumPurchases()->get();
        } catch (\Throwable) {
            return collect(); // premium_purchases.sql not run yet
        }

        return $purchases->filter(fn (Purchase $purchase) => !$purchase->ends_on
            || Carbon::parse($purchase->ends_on)->startOfDay()->gte($date))->values();
    }

    public function hasActivePremium(?Carbon $date = null): bool
    {
        return $this->activePremiumPurchases($date)->isNotEmpty();
    }

    /**
     * Ids of the premium pets.
     *
     * @return int[]
     */
    public function premiumAnimalIds(?Carbon $date = null): array
    {
        return $this->activePremiumPurchases($date)
            ->pluck('animal_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function isPremiumAnimal(int $animalId, ?Carbon $date = null): bool
    {
        return in_array($animalId, $this->premiumAnimalIds($date), true);
    }

    public function plannedOrders() { return $this->hasMany(PlannedOrder::class); }



}

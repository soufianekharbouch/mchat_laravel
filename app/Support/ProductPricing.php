<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\Product;
use App\Models\Purchase;
use Carbon\Carbon;

/**
 * Price of a product for one pet.
 *
 * Trial offer: when the pet has a trial pack still running
 * (paid / active, end date not passed or not started yet), the packs
 * with a "trial_offer_price" are offered at that price.
 * One trial gives the offer only once.
 *
 *   $pricing = ProductPricing::for($product, $animal);
 *   $pricing['price']   // price to pay
 *   $pricing['source']  // regular | promo | trial_offer
 */
class ProductPricing
{
    /**
     * Trial statuses that still give the offer.
     */
    public const TRIAL_RUNNING_STATUSES = ['paid', 'active'];

    /**
     * @return array{
     *   price: float,
     *   source: string,
     *   trial_purchase: ?Purchase,
     *   trial_ends_on: ?string
     * }
     */
    public static function for(Product $product, ?Animal $animal): array
    {
        $regular = [
            'price' => $product->currentPrice(),
            'source' => $product->hasActivePromo() ? 'promo' : 'regular',
            'trial_purchase' => null,
            'trial_ends_on' => null,
        ];

        if (!$animal || !$product->hasTrialOffer()) {
            return $regular;
        }

        $trial = self::runningTrialFor($animal->id);

        if (!$trial) {
            return $regular;
        }

        $offerPrice = (float) $product->trial_offer_price;

        // Never more expensive than the normal / promo price
        if ($offerPrice >= $regular['price']) {
            return $regular;
        }

        return [
            'price' => $offerPrice,
            'source' => 'trial_offer',
            'trial_purchase' => $trial,
            'trial_ends_on' => $trial->ends_on ? Carbon::parse($trial->ends_on)->format('Y-m-d') : null,
        ];
    }

    /**
     * Running trial of a pet whose offer has not been used yet.
     */
    public static function runningTrialFor(int $animalId): ?Purchase
    {
        $today = Carbon::today()->toDateString();

        return Purchase::query()
            ->where('animal_id', $animalId)
            ->where('is_trial', true)
            ->whereIn('status', self::TRIAL_RUNNING_STATUSES)
            ->where(function ($query) use ($today) {
                $query->whereNull('ends_on')
                    ->orWhereDate('ends_on', '>=', $today);
            })
            // Offer already used by another purchase (not cancelled)
            ->whereNotExists(function ($query) {
                $query->selectRaw(1)
                    ->from('purchases as offers')
                    ->whereColumn('offers.trial_purchase_id', 'purchases.id')
                    ->whereIn('offers.status', Purchase::COUNTED_STATUSES);
            })
            ->orderByDesc('id')
            ->first();
    }
}

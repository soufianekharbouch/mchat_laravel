<?php

namespace App\Support;

use App\Models\CustomerAccount;
use App\Models\Product;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Products shown in the app (shop + renew plans page).
 *
 * Only 3 kinds of products are sold in the app:
 *   TP-...       taste packs
 *   REG-...      regular packs
 *   PREMIUM-...  personalized packs
 * Any other code (products created for ONE premium customer) is never shown.
 *
 * Order from what was already bought (by the customer in the shop,
 * by THIS pet on the renew plans page):
 *   - already had a PREMIUM- pack : PREMIUM-, REG-        (no TP-)
 *   - already had a TP- pack      : REG-, PREMIUM-, TP-   (TP- to redo the test)
 *   - nothing yet / visitor       : usual order (sort order of the products)
 */
class ShopCatalog
{
    public const PREFIX_TRIAL = 'TP-';
    public const PREFIX_REGULAR = 'REG-';
    public const PREFIX_PREMIUM = 'PREMIUM-';

    public const HISTORY_PREMIUM = 'premium';
    public const HISTORY_TRIAL = 'trial';

    /** Purchases that count as "already bought" (paid at some point). */
    private const BOUGHT_STATUSES = ['paid', 'active', 'completed', 'expired'];

    public static function kind(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return match (true) {
            str_starts_with($code, self::PREFIX_TRIAL) => self::PREFIX_TRIAL,
            str_starts_with($code, self::PREFIX_REGULAR) => self::PREFIX_REGULAR,
            str_starts_with($code, self::PREFIX_PREMIUM) => self::PREFIX_PREMIUM,
            default => null,
        };
    }

    /** TP- / REG- / PREMIUM- only. */
    public static function isShopCode(?string $code): bool
    {
        return self::kind($code) !== null;
    }

    /** Query: only the products sold in the app. */
    public static function onlyShopCodes(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereRaw('UPPER(code) LIKE ?', [self::PREFIX_TRIAL . '%'])
                ->orWhereRaw('UPPER(code) LIKE ?', [self::PREFIX_REGULAR . '%'])
                ->orWhereRaw('UPPER(code) LIKE ?', [self::PREFIX_PREMIUM . '%']);
        });
    }

    /**
     * What was already bought: premium | trial | null.
     * $animalId given: purchases of this pet only (renew plans page),
     * otherwise all the purchases of the customer (shop).
     * A premium pack keeps its premium_status after the team assigned his
     * custom product, so it is still recognised.
     */
    public static function historyFor(?CustomerAccount $customer, ?int $animalId = null): ?string
    {
        if (!$customer) {
            return null;
        }

        $purchases = Purchase::query()
            ->where(function ($query) use ($customer) {
                $query->where('customer_account_id', $customer->id);

                if ($customer->subscription_id) {
                    $query->orWhere('subscription_id', $customer->subscription_id);
                }
            })
            ->when($animalId, fn ($query) => $query->where('animal_id', $animalId))
            ->whereIn('status', self::BOUGHT_STATUSES);

        $premium = (clone $purchases)->where(function ($query) {
            $query->whereRaw('UPPER(product_code) LIKE ?', [self::PREFIX_PREMIUM . '%'])
                ->orWhereNotNull('premium_status');
        })->exists();

        if ($premium) {
            return self::HISTORY_PREMIUM;
        }

        $trial = (clone $purchases)->where(function ($query) {
            $query->whereRaw('UPPER(product_code) LIKE ?', [self::PREFIX_TRIAL . '%'])
                ->orWhere('is_trial', true);
        })->exists();

        return $trial ? self::HISTORY_TRIAL : null;
    }

    /**
     * Products to show, in the right order, for this history.
     * The usual order (sort_order, price) is kept inside each kind.
     *
     * @param Collection<int, Product> $products
     * @return Collection<int, Product>
     */
    public static function arrange(Collection $products, ?string $history): Collection
    {
        $products = $products->filter(fn (Product $product) => self::isShopCode($product->code))->values();

        $ranks = match ($history) {
            self::HISTORY_PREMIUM => [self::PREFIX_PREMIUM => 0, self::PREFIX_REGULAR => 1],
            self::HISTORY_TRIAL => [self::PREFIX_REGULAR => 0, self::PREFIX_PREMIUM => 1, self::PREFIX_TRIAL => 2],
            default => null,
        };

        if ($ranks === null) {
            return $products;
        }

        return $products
            // Premium customer: no taste pack any more
            ->filter(fn (Product $product) => array_key_exists(self::kind($product->code), $ranks))
            ->map(fn (Product $product, int $index) => ['product' => $product, 'index' => $index])
            ->sortBy([
                fn ($a, $b) => $ranks[self::kind($a['product']->code)] <=> $ranks[self::kind($b['product']->code)],
                fn ($a, $b) => $a['index'] <=> $b['index'],
            ])
            ->pluck('product')
            ->values();
    }

    /** Rank of a product for this history (used to sort other lists). */
    public static function rank(Product $product, ?string $history): int
    {
        $ranks = match ($history) {
            self::HISTORY_PREMIUM => [self::PREFIX_PREMIUM => 0, self::PREFIX_REGULAR => 1],
            self::HISTORY_TRIAL => [self::PREFIX_REGULAR => 0, self::PREFIX_PREMIUM => 1, self::PREFIX_TRIAL => 2],
            default => [],
        };

        return $ranks[self::kind($product->code)] ?? 0;
    }
}

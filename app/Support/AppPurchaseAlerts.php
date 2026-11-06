<?php

namespace App\Support;

use App\Models\Purchase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * New purchases made in the mobile app, not opened by the staff yet
 * (purchases.staff_seen_at empty): banner + NEW badge in Purchases,
 * counter in the menu. A purchase is "seen" when its page is opened or
 * with "Mark all as seen".
 */
class AppPurchaseAlerts
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasColumn('purchases', 'staff_seen_at');
            } catch (Throwable) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    /** App purchases not seen (cancelled ones never alert). */
    public static function unseen(?Builder $query = null): Builder
    {
        return ($query ?? Purchase::query())
            ->where('source', 'app')
            ->whereNull('staff_seen_at')
            ->whereNotIn('status', ['cancelled', 'refunded']);
    }

    public static function count(): int
    {
        if (!self::ready()) {
            return 0;
        }

        try {
            return self::unseen()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public static function isNew(Purchase $purchase): bool
    {
        return self::ready()
            && $purchase->source === 'app'
            && !$purchase->staff_seen_at
            && !in_array($purchase->status, ['cancelled', 'refunded'], true);
    }

    public static function markSeen(Purchase $purchase): void 
    {
        if (self::isNew($purchase)) {
            $purchase->forceFill(['staff_seen_at' => now()])->saveQuietly();
        }
    }

    public static function markAllSeen(): int
    {
        return self::ready() ? self::unseen()->update(['staff_seen_at' => now()]) : 0;
    }
}

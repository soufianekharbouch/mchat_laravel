<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Purchase;

/**
 * PREMIUM-... packs (cats and dogs).
 *
 * 1. The customer buys a product whose code starts with "PREMIUM-".
 *    The purchase is "pending" (untreated): he cannot choose recipes
 *    or delivery days (app, back-office, Shopify auto-planning).
 * 2. The team contacts him, receives the documents of his pet and
 *    creates a product with his recipes (code "CUSTOM-...": never shown
 *    in the app catalog).
 * 3. On the purchase page the team assigns this product: purchase
 *    "done" (treated), the customer is notified and plans his deliveries.
 */
class PremiumPack
{
    public const CODE_PREFIX = 'PREMIUM-';

    /** Products made for one customer: hidden from the app catalog. */
    public const CUSTOM_PREFIX = 'CUSTOM-';

    public const PENDING = 'pending';
    public const DONE = 'done';

    public static function isPremiumCode(?string $code): bool
    {
        return str_starts_with(strtoupper(trim((string) $code)), self::CODE_PREFIX);
    }

    public static function isCustomCode(?string $code): bool
    {
        return str_starts_with(strtoupper(trim((string) $code)), self::CUSTOM_PREFIX);
    }

    public static function isPremiumProduct(?Product $product): bool
    {
        return $product !== null && self::isPremiumCode($product->code);
    }

    /** Premium purchase (untreated or treated). */
    public static function isPremiumPurchase(?Purchase $purchase): bool
    {
        return $purchase !== null && $purchase->premium_status !== null;
    }

    /** Untreated: nothing can be planned. */
    public static function isPending(?Purchase $purchase): bool
    {
        return $purchase !== null && $purchase->premium_status === self::PENDING;
    }

    /** Treated: custom product assigned. */
    public static function isDone(?Purchase $purchase): bool
    {
        return $purchase !== null && $purchase->premium_status === self::DONE;
    }

    /** Same as isPending() (name used by the previous version). */
    public static function needsConsultation(?Purchase $purchase): bool
    {
        return self::isPending($purchase);
    }

    /** Untreated premium purchases (menu badge, filter). */
    public static function pendingQuery()
    {
        return Purchase::query()
            ->where('premium_status', self::PENDING)
            ->whereIn('status', ['pending', 'paid', 'active']);
    }

    public static function pendingCount(): int
    {
        try {
            return self::pendingQuery()->count();
        } catch (\Throwable) {
            return 0; // SQL not run yet
        }
    }

    /** On the product (card, product page, checkout): before the purchase. */
    public static function productMessage(string $lang): string
    {
        return $lang === 'ar'
            ? 'باقة مخصصة: لا تختار الوصفات ولا مواعيد التوصيل الآن. بعد الشراء يتواصل معك فريق التغذية، ثم نحضّر وصفات خاصة بحيوانك فقط.'
            : 'Personalized pack: you do not choose the recipes or the delivery days now. After your purchase our nutrition team contacts you, then we prepare recipes made only for your pet.';
    }

    /** On the purchase while untreated. */
    public static function pendingMessage(string $lang): string
    {
        return $lang === 'ar'
            ? 'سيتواصل معك فريق التغذية قريباً لمعرفة احتياجات حيوانك. عندما تجهز وصفاتك المخصصة سنرسل لك إشعاراً لتنظيم مواعيد التوصيل.'
            : 'Our nutrition team will contact you soon about your pet\'s needs. When your custom recipes are ready we will notify you so you can plan your deliveries.';
    }

    /** Same as pendingMessage() (name used by the previous version). */
    public static function message(string $lang): string
    {
        return self::pendingMessage($lang);
    }

    /** Error returned when planning an untreated purchase. */
    public static function blockedMessage(string $lang = 'en'): string
    {
        return $lang === 'ar'
            ? 'باقة مخصصة: سيتواصل معك فريقنا أولاً لتحضير وصفاتك الخاصة.'
            : 'Personalized pack: our team will contact you first to prepare your custom recipes.';
    }
}

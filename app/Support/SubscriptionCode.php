<?php

namespace App\Support;

use App\Models\Province;
use App\Models\Subscription;

/**
 * Subscription code generator, shared by the back-office and the app.
 *
 * Format: C + province code (2 digits) + sequence (5 digits)
 *   back-office : C0300564
 *   mobile app  : C0300565-APP
 *
 * The 5-digit sequence is global (all provinces, admin + app),
 * so a number is never reused.
 */
class SubscriptionCode
{
    public const APP_SUFFIX = '-APP';

    /**
     * Next code.
     *
     * @param Province|string|null $province Province model or English name
     * @param bool                 $fromApp  true = adds the -APP suffix
     *
     * Call inside a DB::transaction() so lockForUpdate() prevents
     * two simultaneous creations from getting the same number.
     */
    public static function next(Province|string|null $province = null, bool $fromApp = false): string
    {
        $code = 'C'
            . self::provinceCode($province)
            . str_pad((string) self::nextSequence(), 5, '0', STR_PAD_LEFT);

        return $fromApp ? $code . self::APP_SUFFIX : $code;
    }

    /**
     * Province code on 2 digits, '00' when unknown.
     */
    public static function provinceCode(Province|string|null $province): string
    {
        if (is_string($province) && $province !== '') {
            $province = Province::where('name', $province)->first();
        }

        if ($province instanceof Province && filled($province->code)) {
            return str_pad((string) $province->code, 2, '0', STR_PAD_LEFT);
        }

        return '00';
    }

    /**
     * Highest sequence among C0000000 and C0000000-APP codes, + 1.
     * Only digits 4 to 8 are read (the 5-digit sequence).
     */
    public static function nextSequence(): int
    {
        $last = Subscription::query()
            ->where('code', 'REGEXP', '^C[0-9]{7}(-APP)?$')
            ->orderByRaw('CAST(SUBSTRING(code, 4, 5) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('code');

        return $last ? ((int) substr($last, 3, 5)) + 1 : 1;
    }

    /**
     * Same code with the province digits replaced.
     * Used when an app customer created without address
     * ("C0000123-APP") adds his first address.
     * "C0000123-APP" + province 03 -> "C0300123-APP"
     */
    public static function withProvince(?string $code, Province|string|null $province): ?string
    {
        if (!$code || !preg_match('/^C[0-9]{7}(-APP)?$/', $code)) {
            return $code;
        }

        return 'C' . self::provinceCode($province) . substr($code, 3);
    }

    /**
     * true when the code has no province yet ("C00xxxxx").
     */
    public static function hasNoProvince(?string $code): bool
    {
        return $code !== null && str_starts_with($code, 'C00');
    }

    /**
     * true when the code comes from the mobile app.
     */
    public static function isFromApp(?string $code): bool
    {
        return $code !== null && str_ends_with($code, self::APP_SUFFIX);
    }
}

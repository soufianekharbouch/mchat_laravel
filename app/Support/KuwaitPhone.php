<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Kuwaiti phone numbers.
 *
 * The same customer can type "+965 5000 0000", "0096550000000",
 * "96550000000" or only "50000000". Two numbers are the same customer
 * when their 8 local digits are the same.
 */
class KuwaitPhone
{
    public const COUNTRY_CODE = '965';

    /** Only the digits ("+965 5000-0000" -> "96550000000"). */
    public static function digits(?string $value): string
    {
        // Arabic / Persian digits -> latin
        $value = strtr((string) $value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * The 8 local digits of a Kuwaiti number, null when it is not one.
     * "50000000", "96550000000", "0096550000000" -> "50000000"
     */
    public static function local(?string $value): ?string
    {
        $digits = self::digits($value);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, self::COUNTRY_CODE)) {
            $digits = substr($digits, 3);
        }

        return strlen($digits) === 8 ? $digits : null;
    }

    /** "50000000" -> "96550000000" (null when not a Kuwaiti number). */
    public static function international(?string $value): ?string
    {
        $local = self::local($value);

        return $local ? self::COUNTRY_CODE . $local : null;
    }

    /** true when the text looks like a phone number (and not an e-mail). */
    public static function looksLikePhone(?string $value): bool
    {
        $value = trim((string) $value);

        return $value !== ''
            && !str_contains($value, '@')
            && self::local($value) !== null;
    }

    /**
     * SQL expression: only the digits of a column.
     */
    public static function digitsSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '')";
    }

    /**
     * where <column> is the same Kuwaiti number (8 local digits).
     */
    public static function whereSame(Builder $query, string $column, ?string $phone): Builder
    {
        $local = self::local($phone);

        if (!$local) {
            // Not a Kuwaiti number: exact digits
            return $query->whereRaw(self::digitsSql($column) . ' = ?', [self::digits($phone)]);
        }

        $digits = self::digitsSql($column);

        return $query->whereRaw("RIGHT({$digits}, 8) = ?", [$local])
            ->whereRaw("LENGTH({$digits}) IN (8, 11, 13)");
    }
}

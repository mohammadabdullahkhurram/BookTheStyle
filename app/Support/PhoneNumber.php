<?php

namespace App\Support;

/**
 * Format-blind phone comparison for the Booking API: "+1 916 555 0100",
 * "(916) 555-0100", "916-555-0100" and "+19165550100" are all the same
 * caller. Comparison is on DIGITS, ignoring punctuation, spaces, "+" and a
 * leading country code (the last 10 digits are the significant part; both
 * sides get the same treatment, so shorter local numbers still compare
 * whole-to-whole). Display formatting is untouched — numbers are stored
 * and shown exactly as entered.
 */
final class PhoneNumber
{
    /** Digits only, e.g. "+1 (916) 555-0100" → "19165550100". */
    public static function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /** The comparison key: the last 10 digits (all of them when fewer). */
    public static function significant(string $phone): string
    {
        $digits = self::digits($phone);

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /** Same caller? Format-blind, country-code-tolerant, never empty-matches. */
    public static function matches(string $a, string $b): bool
    {
        $a = self::significant($a);
        $b = self::significant($b);

        return $a !== '' && $a === $b;
    }
}

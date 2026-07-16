<?php

namespace App\Support;

/**
 * Hex colour input normalisation. People paste accents from brand guides
 * as "1F6F6B", "#1f6f6b", or with stray whitespace — all of those are the
 * same colour and none should be a validation error. Every surface that
 * accepts an accent (new-salon form, Branding tab, per-widget override)
 * normalises through here BEFORE validating, so the stored value is always
 * canonical #RRGGBB and the regex rule only rejects genuinely invalid input
 * (wrong length, non-hex characters).
 */
final class HexColor
{
    /** Canonical #RRGGBB (uppercase), or null when not a valid 6-digit hex. */
    public static function normalize(?string $value): ?string
    {
        $value = ltrim(trim((string) $value), '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $value) !== 1) {
            return null;
        }

        return '#'.strtoupper($value);
    }

    /**
     * Normalise when possible, otherwise return the input unchanged so the
     * validation rule reports the genuine error on what the user typed.
     */
    public static function tryNormalize(?string $value): string
    {
        return self::normalize($value) ?? trim((string) $value);
    }
}

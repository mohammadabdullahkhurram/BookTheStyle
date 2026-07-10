<?php

namespace App\Support;

/**
 * Display-only money formatting. Prices are stored as integer cents
 * (services.price_cents) with a per-salon ISO currency (salons.currency);
 * NULL cents means "price varies" / not stated. This is presentation only —
 * the app never charges anyone.
 */
final class Money
{
    /** Curated currency => display symbol (prefix). */
    private const SYMBOLS = [
        'USD' => '$',
        'CAD' => 'CA$',
        'AUD' => 'A$',
        'NZD' => 'NZ$',
        'GBP' => '£',
        'EUR' => '€',
        'CHF' => 'CHF ',
        'AED' => 'AED ',
        'ZAR' => 'R',
    ];

    /**
     * Format cents for display: "$45", "$42.50", "€30". Whole amounts drop
     * the decimals — salon menus read as "$45", not "$45.00". Null in,
     * null out (callers render "Price varies" or nothing).
     */
    public static function format(?int $cents, string $currency): ?string
    {
        if ($cents === null) {
            return null;
        }

        $amount = $cents % 100 === 0
            ? number_format(intdiv($cents, 100))
            : number_format($cents / 100, 2);

        return self::symbol($currency).$amount;
    }

    public static function symbol(string $currency): string
    {
        return self::SYMBOLS[$currency] ?? $currency.' ';
    }

    /**
     * @return list<string> supported ISO codes for the salon-settings select
     */
    public static function codes(): array
    {
        return array_keys(self::SYMBOLS);
    }
}

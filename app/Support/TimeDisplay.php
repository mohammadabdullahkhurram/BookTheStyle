<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The one place display times are formatted. The app SPEAKS 'H:i' internally
 * (slot values, wire payloads, route params — never change those), but every
 * human-facing time reads 12-hour with AM/PM. New surfaces should format
 * through here instead of sprinkling format('g:i A') calls.
 */
final class TimeDisplay
{
    /** '14:00' (or any datetime) → '2:00 PM'. Display only. */
    public static function twelveHour(DateTimeInterface|string $time): string
    {
        if (is_string($time)) {
            $time = CarbonImmutable::createFromFormat('H:i', $time);
        }

        return $time->format('g:i A');
    }
}

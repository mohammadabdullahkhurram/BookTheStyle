<?php

namespace App\Support;

/**
 * One-line summary of a stylist's weekly hours for the availability cards:
 * "Weekdays, 8:00 AM – 5:00 PM", "Every day, 9:00 AM – 3:00 PM",
 * "Mon – Sat, varies", "Mon, Wed, Fri, 10:00 AM – 4:00 PM", or
 * "Unavailable" when no working hours exist. Pure minutes-in, string-out.
 */
class AvailabilitySummary
{
    private const SHORT_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * @param  array<int, list<array{0: int, 1: int}>>  $windowsByWeekday  0 = Monday … 6 = Sunday,
     *                                                                     minutes from midnight
     */
    public static function line(array $windowsByWeekday): string
    {
        $signatures = [];

        foreach (range(0, 6) as $weekday) {
            $windows = $windowsByWeekday[$weekday] ?? [];

            if ($windows !== []) {
                usort($windows, fn (array $a, array $b): int => $a[0] <=> $b[0]);
                $signatures[$weekday] = implode(', ', array_map(
                    fn (array $w): string => self::minutes($w[0]).' – '.self::minutes($w[1]),
                    $windows,
                ));
            }
        }

        if ($signatures === []) {
            return __('Unavailable');
        }

        $days = array_keys($signatures);
        $allSame = count(array_unique($signatures)) === 1;

        return self::daySet($days).', '.($allSame ? reset($signatures) : __('varies'));
    }

    /**
     * @param  list<int>  $days  sorted weekday indexes that are ON
     */
    private static function daySet(array $days): string
    {
        if ($days === range(0, 6)) {
            return __('Every day');
        }

        if ($days === range(0, 4)) {
            return __('Weekdays');
        }

        if ($days === [5, 6]) {
            return __('Weekends');
        }

        // A contiguous run reads as a range; scattered days are listed.
        $first = $days[0];
        $last = $days[count($days) - 1];

        if (count($days) > 2 && $days === range($first, $last)) {
            return self::SHORT_DAYS[$first].' – '.self::SHORT_DAYS[$last];
        }

        return implode(', ', array_map(fn (int $d): string => self::SHORT_DAYS[$d], $days));
    }

    public static function minutes(int $minutes): string
    {
        $hour = intdiv($minutes, 60) % 24;
        $minute = $minutes % 60;
        $meridiem = $hour >= 12 ? 'PM' : 'AM';
        $display = $hour % 12 === 0 ? 12 : $hour % 12;

        return sprintf('%d:%02d %s', $display, $minute, $meridiem);
    }
}

<?php

namespace App\Services\VoiceAi;

/**
 * Turns weekly opening hours into the casual spoken phrasing the Voice AI
 * reads aloud. Consecutive days with identical hours group ("Tuesday to
 * Friday"), a single open day pluralizes ("Saturdays"), closed days close
 * the sentence ("closed Sunday and Monday" — listed Sunday-first, the way
 * a wrapped weekend reads naturally). Times speak casually: 9:00 → "nine",
 * 9:30 → "nine thirty"; am/pm drops when a range crosses noon and is
 * therefore unambiguous ("nine to seven"), and stays otherwise
 * ("nine am to eleven am").
 */
final class SpokenHours
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const ONES = [
        1 => 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
        'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen',
    ];

    private const TENS = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty'];

    /**
     * @param  array<int, array{start: int, end: int}|null>  $week  Monday-first
     *                                                              weekday (0–6) → open window in minutes, or null when closed.
     */
    public static function phrase(array $week): string
    {
        // Group consecutive open days with identical hours (Monday-first).
        $groups = [];
        $current = null;
        foreach (range(0, 6) as $day) {
            $window = $week[$day] ?? null;

            if ($window === null) {
                continue;
            }

            if ($current !== null && $current['end_day'] === $day - 1 && $current['window'] === $window) {
                $current['end_day'] = $day;

                continue;
            }

            if ($current !== null) {
                $groups[] = $current;
            }
            $current = ['start_day' => $day, 'end_day' => $day, 'window' => $window];
        }
        if ($current !== null) {
            $groups[] = $current;
        }

        if ($groups === []) {
            return '';
        }

        $parts = array_map(function (array $group): string {
            $days = $group['start_day'] === $group['end_day']
                ? self::DAYS[$group['start_day']].'s'
                : self::DAYS[$group['start_day']].' to '.self::DAYS[$group['end_day']];

            return $days.' '.self::range($group['window']['start'], $group['window']['end']);
        }, $groups);

        // Closed days, Sunday-first so a wrapped weekend reads naturally.
        $closed = [];
        foreach ([6, 0, 1, 2, 3, 4, 5] as $day) {
            if (($week[$day] ?? null) === null) {
                $closed[] = self::DAYS[$day];
            }
        }

        if ($closed !== []) {
            $parts[] = 'closed '.self::joinNatural($closed);
        }

        return implode(', ', $parts);
    }

    /** A spoken time range, dropping am/pm when crossing noon disambiguates. */
    public static function range(int $startMinutes, int $endMinutes): string
    {
        $crossesNoon = $startMinutes < 12 * 60 && $endMinutes >= 12 * 60;

        return $crossesNoon
            ? self::time($startMinutes).' to '.self::time($endMinutes)
            : self::time($startMinutes, withSuffix: true).' to '.self::time($endMinutes, withSuffix: true);
    }

    /** One spoken clock time: 540 → "nine"; 690 → "eleven thirty" (+ optional am/pm). */
    public static function time(int $minutes, bool $withSuffix = false): string
    {
        $hour24 = intdiv($minutes, 60) % 24;
        $minute = $minutes % 60;
        $hour12 = $hour24 % 12 === 0 ? 12 : $hour24 % 12;

        $spoken = self::ONES[$hour12] ?? 'twelve';

        if ($minute > 0) {
            $spoken .= ' '.self::minuteWords($minute);
        }

        if ($withSuffix) {
            $spoken .= $hour24 < 12 ? ' am' : ' pm';
        }

        return $spoken;
    }

    /** @param  list<string>  $items */
    public static function joinNatural(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    private static function minuteWords(int $minute): string
    {
        if ($minute < 10) {
            return 'oh '.self::ONES[$minute];
        }

        if ($minute < 20) {
            return self::ONES[$minute];
        }

        $tens = self::TENS[intdiv($minute, 10)];
        $ones = $minute % 10;

        return $ones === 0 ? $tens : $tens.'-'.self::ONES[$ones];
    }
}

<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Minimal, dependency-free RFC 5545 (iCalendar) writer helpers: text escaping,
 * UTC date-time formatting, and 75-octet line folding. Kept hand-rolled to
 * avoid a dependency; the output validates in Google / Apple / Outlook.
 *
 * All date-times are emitted as UTC ("...Z") form, which is unambiguous and
 * DST-safe — the stored instants are already absolute UTC, so no VTIMEZONE is
 * needed and clients render each event at the correct local wall-clock.
 */
class Ics
{
    /**
     * Escape a TEXT value (RFC 5545 §3.3.11): backslash, semicolon, comma, and
     * newlines. Carriage returns are dropped; newlines become the literal "\n".
     */
    public static function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\r", "\n"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value,
        );
    }

    /**
     * Format an instant as a UTC iCalendar date-time (e.g. 20260622T140000Z).
     */
    public static function dt(DateTimeInterface $when): string
    {
        return \DateTimeImmutable::createFromInterface($when)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    /**
     * Fold a single content line to 75 octets max, continuation lines starting
     * with a single space (RFC 5545 §3.1). Operates on bytes; unfolding rejoins
     * any split multi-byte sequence.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = substr($line, 0, 75);
        $rest = substr($line, 75);

        while (strlen($rest) > 74) {
            $folded .= "\r\n ".substr($rest, 0, 74);
            $rest = substr($rest, 74);
        }

        return $folded."\r\n ".$rest;
    }

    /**
     * Join content lines into a CRLF-delimited, folded iCalendar body.
     *
     * @param  list<string>  $lines
     */
    public static function join(array $lines): string
    {
        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }
}

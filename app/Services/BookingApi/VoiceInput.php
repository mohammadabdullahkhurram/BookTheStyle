<?php

namespace App\Services\BookingApi;

use Illuminate\Http\Request;

/**
 * Normalizes the wire shapes GHL Voice AI Custom Actions actually send.
 * Real requests arrive as a query string with an empty body (not JSON),
 * and values can be double-URL-encoded ("Hair Cut" → "Hair%2520Cut", which
 * PHP's one decode leaves as the literal "Hair%20Cut"). Client fields may
 * come nested (JSON `client: {}` / query `client[name]`) or flattened
 * (`client_name` — also what PHP makes of a query key like `client.name`).
 *
 * Decoding is defensive and idempotent: a value is only decoded while it
 * still contains a real percent-encoded sequence (%XX), capped at two
 * passes, so legitimate text — including names with a bare "%" — is never
 * corrupted, and an already-clean value round-trips unchanged.
 */
final class VoiceInput
{
    /** One pass undoes GHL's double-encoding remnant; two is the safety cap. */
    private const MAX_DECODE_PASSES = 2;

    /** Percent-decode a possibly (double-)encoded scalar until stable, then trim. */
    public static function decode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        for ($pass = 0; $pass < self::MAX_DECODE_PASSES; $pass++) {
            if (preg_match('/%[0-9A-Fa-f]{2}/', $value) !== 1) {
                break;
            }

            $decoded = rawurldecode($value);

            if ($decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        return trim($value);
    }

    /**
     * Decode a datetime and repair an ISO 8601 "+" offset that a query-string
     * parse turned into a space ("…T11:30:00 04:00" → "…T11:30:00+04:00").
     * Only a space directly after a time component is repaired, so plain
     * "2026-07-27 11:30" (date-space-time, no offset) is left alone.
     */
    public static function datetime(mixed $value): mixed
    {
        $value = self::decode($value);

        if (! is_string($value)) {
            return $value;
        }

        return preg_replace('/(\d{2}:\d{2}(?::\d{2})?) (\d{2}:?\d{2})$/', '$1+$2', $value);
    }

    /**
     * The client payload in any accepted shape: nested array/object, or
     * flattened client_name / client_phone / client_email keys. Nested
     * values win when both are present. Empty → [] so the `client`
     * required rule fails with a clear message.
     *
     * @return array<string, mixed>
     */
    public static function client(Request $request): array
    {
        $client = $request->input('client');
        $client = is_array($client) ? $client : [];

        foreach (['name', 'phone', 'email'] as $field) {
            $value = self::decode($client[$field] ?? $request->input("client_{$field}"));

            if ($value !== null && $value !== '') {
                $client[$field] = $value;
            } else {
                unset($client[$field]);
            }
        }

        return $client;
    }
}

<?php

namespace App\Support;

use App\Models\Salon;
use SensitiveParameter;

/**
 * Per-salon Booking API bearer tokens. Format: btsk_{salonId}_{40 hex chars}
 * — the embedded salon id makes resolution O(1) (an id is not a secret; the
 * 160-bit random suffix is), and only the sha256 hash is stored. The
 * plaintext exists exactly once, at generation time, and is never logged.
 */
final class BookingApiToken
{
    /** Generate + persist a new token for the salon; returns the plaintext ONCE. */
    public static function generate(Salon $salon): string
    {
        // No real btsk_ tokens for demo salons — the voice API must be
        // unreachable from a demo, full stop.
        if ($salon->is_demo) {
            throw new \RuntimeException('Demo salons cannot hold API tokens.');
        }

        $plaintext = 'btsk_'.$salon->id.'_'.bin2hex(random_bytes(20));

        $salon->forceFill([
            'api_token_hash' => hash('sha256', $plaintext),
            'api_token_generated_at' => now(),
        ])->save();

        return $plaintext;
    }

    /**
     * Resolve the salon a bearer token belongs to — null for anything invalid
     * (malformed, unknown salon, inactive salon, no token issued, mismatch).
     * Constant-time comparison; the token is never persisted or logged.
     */
    public static function resolveSalon(#[SensitiveParameter] ?string $bearer): ?Salon
    {
        if ($bearer === null || preg_match('/^btsk_(\d+)_[0-9a-f]{40}$/', $bearer, $m) !== 1) {
            return null;
        }

        // Demo salons never hold API tokens (and are rejected here regardless).
        $salon = Salon::query()->whereKey((int) $m[1])->where('active', true)->where('is_demo', false)->first();

        if ($salon === null || $salon->api_token_hash === null) {
            return null;
        }

        return hash_equals($salon->api_token_hash, hash('sha256', $bearer)) ? $salon : null;
    }
}

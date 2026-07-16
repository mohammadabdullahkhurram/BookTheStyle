<?php

namespace App\Services\Calendar;

use App\Models\CalendarConnection;
use App\Models\User;

/**
 * Manages a user's personal calendar feed token (Phase 5). The token is the
 * bearer credential in the /cal/{token}.ics URL; only its SHA-256 hash is
 * stored, and the feed is resolved by hashing the presented token. Tokens are
 * high-entropy (32 random bytes) and rotatable — regenerating instantly
 * invalidates the old URL; revoking clears it.
 */
class CalendarFeedService
{
    /**
     * Generate or rotate the user's token. Returns the plaintext once (for
     * display); only the hash is persisted.
     */
    public function regenerate(User $user): string
    {
        $token = bin2hex(random_bytes(32)); // 64 hex chars

        $connection = $user->calendarConnection()->first() ?? $user->calendarConnection()->make();
        $connection->token_hash = self::hash($token);
        // A new token is a new, unfetched link: the status starts over.
        $connection->last_used_at = null;
        $connection->last_client = null;
        $connection->fetch_count = 0;
        $user->calendarConnection()->save($connection);
        $user->setRelation('calendarConnection', $connection);

        return $token;
    }

    /**
     * Revoke the user's feed (clears the hash → old URL stops resolving).
     */
    public function revoke(User $user): void
    {
        $connection = $user->calendarConnection()->first();

        if ($connection !== null) {
            $connection->token_hash = null;
            $connection->last_used_at = null;
            $connection->last_client = null;
            $connection->fetch_count = 0;
            $connection->save();
        }
    }

    /**
     * Resolve a presented token to its owning user by hash, or null if no active
     * feed matches. Records fetch evidence on a hit — throttled, because this
     * is a hot public endpoint: the row is written only when the last record
     * is older than five minutes (or the calendar client changed), so a
     * fast-polling client costs one UPDATE per window, not per request.
     * fetch_count is therefore a sampled count of polling windows, not hits.
     */
    public function resolve(string $token, ?string $userAgent = null): ?User
    {
        if ($token === '') {
            return null;
        }

        $connection = CalendarConnection::query()
            ->whereNotNull('token_hash')
            ->where('token_hash', self::hash($token))
            ->with('user')
            ->first();

        if ($connection === null) {
            return null;
        }

        $client = self::clientLabel($userAgent);
        $stale = $connection->last_used_at === null
            || $connection->last_used_at->lt(now()->subMinutes(5));

        if ($stale || ($client !== null && $client !== $connection->last_client)) {
            $connection->forceFill([
                'last_used_at' => now(),
                'last_client' => $client ?? $connection->last_client,
                'fetch_count' => $connection->fetch_count + 1,
            ])->save();
        }

        return $connection->user;
    }

    /**
     * A human label for the fetching calendar app, from its User-Agent.
     */
    public static function clientLabel(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'Google') => 'Google Calendar',
            str_contains($userAgent, 'CalendarAgent'),
            str_contains($userAgent, 'dataaccessd'),
            str_contains($userAgent, 'iCal') => 'Apple Calendar',
            stripos($userAgent, 'outlook') !== false,
            str_contains($userAgent, 'Microsoft') => 'Outlook',
            default => 'a calendar app',
        };
    }

    public function subscribeUrl(string $token): string
    {
        return route('cal.feed', ['token' => $token]);
    }

    /**
     * The webcal:// one-click subscribe URL (Apple/Google) for the same path.
     */
    public function webcalUrl(string $token): string
    {
        return preg_replace('#^https?://#', 'webcal://', $this->subscribeUrl($token))
            ?? $this->subscribeUrl($token);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

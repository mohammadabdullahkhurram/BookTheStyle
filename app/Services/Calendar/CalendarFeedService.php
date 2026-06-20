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
        $connection->last_used_at = null;
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
            $connection->save();
        }
    }

    /**
     * Resolve a presented token to its owning user by hash, or null if no active
     * feed matches. Touches last_used_at on a hit.
     */
    public function resolve(string $token): ?User
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

        $connection->last_used_at = now();
        $connection->save();

        return $connection->user;
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

<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Structured, greppable log lines for authentication outcomes. Born of a
 * production afternoon lost to five different login failures all presenting
 * as "These credentials do not match our records" with a silent log: every
 * auth gate now names exactly which check failed, so `grep "Auth: "` answers
 * "why can't this person log in?" in one line.
 *
 * Reasons in use: user_not_found, bad_password, account_inactive, throttled,
 * must_change_password_pending, must_change_password_blocked, no_salon_access.
 *
 * The attempted email is logged deliberately (it is the lookup key for the
 * next support request); passwords and other secrets never are.
 */
final class AuthLog
{
    public static function warn(string $reason, ?string $email, ?User $user = null): void
    {
        Log::warning('Auth: '.$reason, [
            'reason' => $reason,
            'email' => $email,
            'user_id' => $user?->id,
            'ip' => request()->ip(),
            'host' => request()->getHost(),
        ]);
    }
}

<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\AuthLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * The credential check behind Fortify's login pipeline, replacing the stock
 * guard attempt so every distinct failure is logged with its real reason.
 *
 * The enumeration line is drawn exactly at password verification:
 * BEFORE it (unknown email / wrong password) the user-facing response stays
 * the indistinguishable generic message — only the log knows which it was.
 * AFTER it the user has proven they own the account, so honesty leaks
 * nothing: a deactivated account is told so, with a way forward, instead of
 * being gaslit about their credentials.
 */
class AuthenticateUser
{
    public function __invoke(Request $request): ?User
    {
        $email = Str::lower((string) $request->input(Fortify::username()));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            AuthLog::warn('user_not_found', $email);

            return null; // Generic message — must stay identical to bad_password.
        }

        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            AuthLog::warn('bad_password', $email, $user);

            return null; // Generic message — must stay identical to user_not_found.
        }

        // Credentials proven; enumeration is no longer a concern. A user whose
        // memberships were all deactivated (and who has no agency-side reach)
        // is refused a session and told the actual reason.
        if (! $user->hasAnySalonAccess() && $user->salonMemberships()->exists()) {
            AuthLog::warn('account_inactive', $email, $user);

            throw ValidationException::withMessages([
                Fortify::username() => __('Your account has been deactivated. Contact your salon owner or agency administrator to restore access.'),
            ]);
        }

        // Preserve the stock pipeline's rehash-on-login (the 'hashed' cast
        // re-hashes the verified plaintext under the current parameters).
        if (config('hashing.rehash_on_login', true) && Hash::needsRehash((string) $user->password)) {
            $user->forceFill(['password' => (string) $request->input('password')])->save();
        }

        // A user with no salon reach at all (nothing ever granted) still gets
        // a session: the dashboard shows an honest empty state, and the Login
        // listener logs no_salon_access so support can see it instantly.
        return $user;
    }
}

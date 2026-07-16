<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\AuthLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The forced first-login password change. A user provisioned by an admin logs
 * in with a temporary password and is routed here by EnsurePasswordChanged
 * until they set a real one. Clearing the flag releases them to the app.
 */
class PasswordChangeController extends Controller
{
    public function show(): View
    {
        return view('pages.auth.force-password');
    }

    public function update(Request $request): RedirectResponse
    {
        try {
            $request->validate([
                'current_password' => ['required', 'current_password'],
                'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
            ], [
                // Honest and actionable: this rejection is how a user who lost
                // their temporary password discovers the way out.
                'current_password.current_password' => __('That temporary password is incorrect. If you no longer have it, log out and use "Forgot password" — completing a reset also replaces the temporary password. Or ask your administrator to issue a new one.'),
            ]);
        } catch (ValidationException $e) {
            if (array_key_exists('current_password', $e->errors())) {
                // The lockout signal: a forced-change user whose temp password
                // doesn't verify. This exact state trapped a production user.
                AuthLog::warn('must_change_password_blocked', $request->user()?->email, $request->user());
            }

            throw $e;
        }

        $user = $request->user();
        $user->forceFill([
            'password' => $request->string('password')->value(),
            'must_change_password' => false,
        ])->save();

        // Refresh the session fingerprint after a credential change.
        $request->session()->regenerate();
        Auth::setUser($user);

        return redirect()->intended(route('dashboard'))
            ->with('status', __('Your password has been updated.'));
    }
}

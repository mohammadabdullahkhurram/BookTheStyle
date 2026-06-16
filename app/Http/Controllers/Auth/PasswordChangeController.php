<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

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
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

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

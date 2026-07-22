<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Programmatic sign-in for the launch-video capture harness
 * (scripts/capture-launch-assets.mjs): the script authenticates the demo
 * owner through a session redirect instead of typing into the login form,
 * so no capture ever races the auth UI.
 *
 * LOCAL ONLY, twice over: the route is registered only when
 * APP_ENV=local (routes/web.php), and the controller re-asserts the
 * environment before touching auth. It can never exist in production —
 * deploys cache routes with APP_ENV=production, where registration is
 * skipped entirely.
 */
class CaptureLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $user = User::query()->where('email', (string) $request->query('email'))->firstOrFail();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect((string) $request->query('to', '/dashboard'));
    }
}

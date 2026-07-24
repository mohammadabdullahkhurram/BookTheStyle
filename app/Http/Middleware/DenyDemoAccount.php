<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks demo visitors from personal/account surfaces (profile, security,
 * anything on the app host that presumes a real person behind the session).
 * Demo accounts are per-visitor throwaways, so these pages are dead ends for
 * them — a demo visitor who types the URL is bounced back into their demo
 * (the entry route re-resolves their session salon) instead of 404ing into
 * confusion. Real users, including agency staff, pass through untouched.
 *
 * Route-level by design: hiding the nav entry is cosmetic; this is the guard.
 */
class DenyDemoAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isDemoAccount()) {
            return redirect()->route('demo.enter');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tenant group's auth gate, with ONE carve-out: the static demo host is
 * a logged-out guest preview — no session is required (or created) there,
 * so authentication is skipped entirely and ResolveSalon supplies the
 * showcase context instead. Every real salon subdomain authenticates
 * exactly as before (same parent middleware, same redirect-to-login).
 */
class AuthenticateUnlessDemo extends Authenticate
{
    public function handle($request, Closure $next, ...$guards): Response
    {
        if (DemoMode::isDemoHost($request)) {
            return $next($request);
        }

        return parent::handle($request, $next, ...$guards);
    }
}

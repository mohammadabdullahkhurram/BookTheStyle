<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a user flagged with `must_change_password` (i.e. still on their
 * admin-issued temporary password) to set a new password before reaching any
 * other authenticated page. Runs on every web request; it allows only the
 * change-password screen itself and logout so the user is never trapped.
 */
class EnsurePasswordChanged
{
    /**
     * Route names the flagged user is still allowed to reach.
     *
     * @var list<string>
     */
    protected array $allowed = [
        'password.change',
        'password.change.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password && ! $request->routeIs($this->allowed)) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}

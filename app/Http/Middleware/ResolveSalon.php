<?php

namespace App\Http\Middleware;

use App\Models\Salon;
use App\Support\ReservedSlugs;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active salon for a request from the subdomain slug and enforces
 * membership.
 *
 * The slug is the {salon} domain parameter of the salon subdomain group
 * ({slug}.{app.domain}) — i.e. it comes from the request Host, not a path
 * segment. An unknown or inactive slug is a 404 (the salon simply isn't a
 * reachable tenant). The authenticated user MUST then have an active membership
 * for that salon (or be a privileged agency user within the same agency);
 * otherwise we abort 403. This is the request-level tenant-isolation boundary —
 * it is what stops a logged-in user from reaching another salon's subdomain.
 *
 * On success the resolved Salon is bound in the container as `currentSalon`,
 * re-bound as the `salon` route parameter (so component mounts receive the
 * active-checked instance), and shared to views.
 */
class ResolveSalon
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        // The {salon} domain parameter. Implicit binding may already have turned
        // it into a Salon; otherwise it is the raw slug string from the Host.
        $param = $request->route('salon');
        $slug = $param instanceof Salon ? $param->slug : $param;

        if (! is_string($slug) || $slug === '') {
            abort(404);
        }

        // Safety net: reserved system subdomains (app, register, www, cal, …) are
        // never tenants. They have their own explicit route groups registered
        // ahead of this wildcard, so this only fires if one slips through.
        if (ReservedSlugs::isReserved($slug)) {
            abort(404);
        }

        // Unknown OR inactive slug → 404: it is not a reachable tenant. (Active
        // status is checked here, not by route binding, so deactivated salons
        // disappear from the public subdomain entirely.)
        $salon = Salon::query()
            ->where('slug', $slug)
            ->where('active', true)
            ->first();

        if ($salon === null) {
            abort(404);
        }

        // The ownership check. No salon data is exposed before this passes.
        if (! $user->belongsToSalon($salon)) {
            abort(403);
        }

        app()->instance('currentSalon', $salon);
        $request->route()?->setParameter('salon', $salon);
        View::share('currentSalon', $salon);

        return $next($request);
    }
}

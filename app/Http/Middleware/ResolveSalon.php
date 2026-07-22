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
 * THE DEMO EXCEPTION — the one salon surface not resolved from a hostname.
 * The slug `demo` is the static demo.{app.domain} host (hand-created in
 * hPanel; this hosting cannot serve runtime-minted subdomains, see
 * docs/DEPLOY.md). WHICH demo salon it shows is never in the URL: it comes
 * from the visitor's session (`demo_salon_id`), so one visitor can never
 * address another's demo. The `is_demo` filters cut BOTH directions: the
 * session lookup accepts only demo salons (a tampered pointer at a real
 * salon resolves nothing), and the slug lookup accepts only real salons (a
 * demo slug is never a reachable tenant subdomain).
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

        $salon = $slug === 'demo'
            ? $this->demoSalonFromSession($request)
            : $this->tenantBySlug($slug);

        if ($salon === null) {
            if ($slug === 'demo') {
                // A dead demo pointer (expired, swept, tampered, or never
                // provisioned) bounces to the entry for a fresh demo rather
                // than 404ing mid-tour.
                return redirect()->route('demo.enter');
            }

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

    /**
     * A real tenant, by subdomain slug. Unknown OR inactive → null → 404: it
     * is not a reachable tenant. (Active status is checked here, not by route
     * binding, so deactivated salons disappear from the public subdomain
     * entirely.) Demo salons are excluded outright — they are reachable only
     * through the session on the static demo host, never at {slug}.
     */
    private function tenantBySlug(string $slug): ?Salon
    {
        // Safety net: reserved system subdomains (app, register, www, cal, …) are
        // never tenants. They have their own explicit route groups registered
        // ahead of this wildcard, so this only fires if one slips through.
        if (ReservedSlugs::isReserved($slug)) {
            return null;
        }

        return Salon::query()
            ->where('slug', $slug)
            ->where('active', true)
            ->where('is_demo', false)
            ->first();
    }

    /**
     * The visitor's own demo salon, from their session — and ONLY a live demo:
     * flagged is_demo, active, and unexpired. Anything else resolves nothing.
     */
    private function demoSalonFromSession(Request $request): ?Salon
    {
        $id = $request->session()->get('demo_salon_id');

        if ($id === null) {
            return null;
        }

        return Salon::query()
            ->whereKey($id)
            ->where('is_demo', true)
            ->where('active', true)
            ->where('demo_expires_at', '>', now())
            ->first();
    }
}

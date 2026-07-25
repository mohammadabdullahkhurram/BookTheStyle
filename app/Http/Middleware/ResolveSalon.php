<?php

namespace App\Http\Middleware;

use App\Enums\SalonRole;
use App\Models\Salon;
use App\Support\DemoMode;
use App\Support\ReservedSlugs;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
 * THE DEMO EXCEPTION — the one salon surface not resolved from a hostname
 * slug lookup. The slug `demo` is the static demo.{app.domain} host
 * (hand-created in hPanel; this hosting cannot serve runtime-minted
 * subdomains, see docs/DEPLOY.md). It always renders THE canonical showcase
 * salon (DemoMode::SHOWCASE_SLUG) as a LOGGED-OUT guest preview: no auth is
 * required or consulted, and a request-scoped VIEWER (the showcase owner)
 * is installed via Auth::setUser purely so the dashboard shell — built
 * around a member — can render. setUser never writes the session, sets no
 * cookie identity and fires no Login event; the chrome still presents as
 * logged out (demo context drives the UI). The `is_demo` filters cut BOTH
 * directions as always: the showcase lookup accepts only demo salons, and
 * the slug lookup accepts only real salons (a demo slug is never a
 * reachable tenant subdomain).
 *
 * On success the resolved Salon is bound in the container as `currentSalon`,
 * re-bound as the `salon` route parameter (so component mounts receive the
 * active-checked instance), and shared to views.
 */
class ResolveSalon
{
    public function handle(Request $request, Closure $next): Response
    {
        // The {salon} domain parameter. Implicit binding may already have turned
        // it into a Salon; otherwise it is the raw slug string from the Host.
        $param = $request->route('salon');
        $slug = $param instanceof Salon ? $param->slug : $param;

        if (! is_string($slug) || $slug === '') {
            abort(404);
        }

        $isDemo = $slug === 'demo';

        if ($isDemo) {
            $salon = DemoMode::showcaseSalon();

            // Not seeded yet (fresh deploy before demo:seed-showcase ran).
            abort_if($salon === null, 404);

            $this->installViewer($salon);
        } else {
            $user = $request->user();

            if ($user === null) {
                abort(403);
            }

            $salon = $this->tenantBySlug($slug);

            if ($salon === null) {
                abort(404);
            }

            // The ownership check. No salon data is exposed before this passes.
            if (! $user->belongsToSalon($salon)) {
                abort(403);
            }
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
     * through the static demo host, never at {slug}.
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
     * The request-scoped guest-preview viewer: the showcase owner, set on
     * the guard for THIS request only. Auth::setUser writes nothing to the
     * session and fires no auth events — any real session the visitor has
     * elsewhere is neither read nor touched, and the next request starts
     * from scratch. This exists solely because the dashboard shell renders
     * around a member; the demo chrome still presents as logged out.
     */
    private function installViewer(Salon $salon): void
    {
        $owner = $salon->memberships()
            ->where('salon_role', SalonRole::Owner->value)
            ->with('user')
            ->first()?->user;

        abort_if($owner === null, 404);

        Auth::setUser($owner);
    }
}

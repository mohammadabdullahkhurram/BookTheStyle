<?php

namespace App\Http\Middleware;

use App\Models\Salon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active salon for a request and enforces membership.
 *
 * The salon id comes from the route ({salon}) or, failing that, the session.
 * The authenticated user MUST have an active membership for that salon (or be a
 * privileged agency user within the same agency); otherwise we abort 403. This
 * is the request-level tenant-isolation boundary — it is what stops a user
 * swapping a salon id in the URL to reach another salon (IDOR).
 *
 * On success the resolved Salon is bound in the container as `currentSalon`
 * (consumed by SalonScope) and shared to views.
 */
class ResolveSalon
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $salonId = $request->route('salon') ?? $request->session()->get('current_salon_id');

        // A route param may already be a bound Salon model; normalise to id.
        if ($salonId instanceof Salon) {
            $salonId = $salonId->id;
        }

        if (empty($salonId)) {
            abort(404);
        }

        $salon = Salon::find($salonId);

        if ($salon === null) {
            abort(404);
        }

        // The ownership check. No salon data is exposed before this passes.
        if (! $user->belongsToSalon($salon)) {
            abort(403);
        }

        app()->instance('currentSalon', $salon);
        $request->session()->put('current_salon_id', $salon->id);
        View::share('currentSalon', $salon);

        return $next($request);
    }
}

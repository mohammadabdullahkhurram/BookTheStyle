<?php

namespace App\Http\Middleware;

use App\Services\Diagnostics\ConnectionDiagnostics;
use App\Support\BookingApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the Voice-AI Booking API. The salon is resolved FROM
 * the token (never from the URL or body — nothing tamperable), attached to
 * the request, and everything downstream scopes to it. Uniform 401 for
 * anything invalid; the token itself is never logged.
 */
class AuthenticateBookingApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $salon = BookingApiToken::resolveSalon($request->bearerToken());

        if ($salon === null) {
            return response()->json([
                'success' => false,
                'error' => 'unauthenticated',
                'message' => __('Invalid or missing API token.'),
            ], 401);
        }

        $request->attributes->set('bookingApiSalon', $salon);

        // The "Check connections" round-trip indicator: remember the last
        // AUTHENTICATED call per salon (never the token, never the payload
        // PII) so the diagnostics page can confirm GHL genuinely reached us.
        Cache::put(
            ConnectionDiagnostics::lastCallKey($salon),
            ['at' => now()->toIso8601String(), 'path' => '/'.ltrim($request->path(), '/')],
            now()->addHours(2),
        );

        return $next($request);
    }
}

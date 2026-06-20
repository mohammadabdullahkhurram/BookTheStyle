<?php

namespace App\Http\Controllers;

use App\Services\Calendar\CalendarFeedService;
use App\Services\Calendar\PersonalCalendarFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves a user's personal ICS calendar feed at GET /cal/{token}.ics on the
 * application host (no session — the token is the only credential). Unknown or
 * revoked tokens 404 without revealing validity. On-demand HTTP only: no queue
 * or worker, in line with the shared-hosting / $0-spend constraints.
 */
class CalendarFeedController extends Controller
{
    public function __invoke(
        Request $request,
        CalendarFeedService $service,
        PersonalCalendarFeed $feed,
        string $token,
    ): Response {
        $user = $service->resolve($token);

        abort_if($user === null, 404);

        $body = $feed->toIcs($user);
        $etag = '"'.sha1($body).'"';

        // Conditional GET: clients poll often; skip re-sending an unchanged feed.
        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response('', 304, $this->cacheHeaders($etag));
        }

        return response($body, 200, array_merge($this->cacheHeaders($etag), [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="bookthestyle.ics"',
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function cacheHeaders(string $etag): array
    {
        return [
            // Per-token secret; cache privately and revalidate within 15 minutes.
            'Cache-Control' => 'private, max-age=900',
            'ETag' => $etag,
        ];
    }
}

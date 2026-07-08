<?php

namespace App\Services\Ghl;

use App\Models\SalonGhlConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;
use Throwable;

/**
 * Minimal GoHighLevel v2 API client for the Phase 6a endpoints: list calendars,
 * fetch one calendar (team members), list location users.
 *
 * Endpoint shapes verified against GHL's published OpenAPI specs
 * (github.com/GoHighLevel/highlevel-api-docs, apps/calendars.json +
 * apps/users.json): base https://services.leadconnectorhq.com, Bearer auth with
 * the salon's Private Integration Token, and a required Version header that
 * differs per API family — 2021-04-15 for /calendars, 2021-07-28 for /users.
 *
 * Requests are throttled per location to stay under GHL's burst limit of 100
 * requests / 10 s per location, and retried with backoff on 429/5xx/network
 * failures. The token is never logged and never appears in exceptions.
 */
class GhlClient
{
    public const BASE_URL = 'https://services.leadconnectorhq.com';

    public const CALENDARS_VERSION = '2021-04-15';

    public const USERS_VERSION = '2021-07-28';

    /** Stay safely under GHL's 100 requests / 10 s per-location burst limit. */
    public const BURST_LIMIT = 90;

    public const BURST_WINDOW_SECONDS = 10;

    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        private readonly string $locationId,
    ) {}

    public static function fromConnection(SalonGhlConnection $connection): self
    {
        if (! $connection->hasToken() || blank($connection->location_id)) {
            throw GhlApiException::notConfigured();
        }

        return new self((string) $connection->private_integration_token, (string) $connection->location_id);
    }

    /**
     * All calendars in the salon's location.
     *
     * @return list<GhlCalendar>
     */
    public function calendars(): array
    {
        $data = $this->get('/calendars/', self::CALENDARS_VERSION, ['locationId' => $this->locationId]);

        return array_map(
            fn (array $calendar): GhlCalendar => GhlCalendar::fromArray($calendar),
            array_values(array_filter((array) ($data['calendars'] ?? []), 'is_array')),
        );
    }

    /**
     * One calendar, including its team members.
     */
    public function calendar(string $calendarId): GhlCalendar
    {
        $data = $this->get('/calendars/'.$calendarId, self::CALENDARS_VERSION);

        $calendar = $data['calendar'] ?? null;

        return GhlCalendar::fromArray(is_array($calendar) ? $calendar : []);
    }

    /**
     * All users (potential calendar team members) in the salon's location.
     *
     * @return list<GhlUser>
     */
    public function users(): array
    {
        $data = $this->get('/users/', self::USERS_VERSION, ['locationId' => $this->locationId]);

        return array_map(
            fn (array $user): GhlUser => GhlUser::fromArray($user),
            array_values(array_filter((array) ($data['users'] ?? []), 'is_array')),
        );
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, string $version, array $query = []): array
    {
        $this->throttle();

        try {
            $response = $this->pending($version)->get($path, $query);
        } catch (ConnectionException) {
            throw GhlApiException::network();
        }

        if ($response->failed()) {
            throw GhlApiException::fromStatus($response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function pending(string $version): PendingRequest
    {
        // Retry 429/5xx/network with backoff; other failures (401, 404, …) are
        // returned (throw: false) and mapped to a GhlApiException by get().
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->token)
            ->withHeaders(['Version' => $version])
            ->acceptJson()
            ->timeout(15)
            ->retry(
                [500, 1500],
                when: fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException
                        && in_array($e->response->status(), [429, 500, 502, 503, 504], true)),
                throw: false,
            );
    }

    /**
     * Take a slot in the per-location burst window, waiting briefly when the
     * window is full. Bounded (~12 s > one full window) so a saturated limiter
     * degrades into a clear rate-limit error instead of hanging the request.
     */
    private function throttle(): void
    {
        $key = 'ghl-api:'.$this->locationId;

        for ($i = 0; $i < 120; $i++) {
            $attempt = RateLimiter::attempt($key, self::BURST_LIMIT, fn (): bool => true, self::BURST_WINDOW_SECONDS);

            if ($attempt !== false) {
                return;
            }

            usleep(100_000);
        }

        throw GhlApiException::rateLimited();
    }
}

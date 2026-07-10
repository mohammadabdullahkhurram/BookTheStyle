<?php

namespace App\Services\Ghl;

use App\Models\SalonGhlConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;
use Throwable;

/**
 * Minimal GoHighLevel v2 API client for Phase 6: list calendars, fetch one
 * calendar (team members), list location users, upsert contacts, and
 * create/update calendar appointments.
 *
 * Endpoint shapes verified against GHL's published OpenAPI specs
 * (github.com/GoHighLevel/highlevel-api-docs: apps/calendars.json,
 * apps/users.json, apps/contacts.json): base
 * https://services.leadconnectorhq.com, Bearer auth with the salon's Private
 * Integration Token, and a required Version header that differs per API
 * family — 2021-04-15 for /calendars, 2021-07-28 for /users and /contacts.
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

    public const CONTACTS_VERSION = '2021-07-28';

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
     * Create a per-user availability schedule (weekly wday rules + date
     * overrides, IANA timezone) — Phase 6e's availability mirror. Returns the
     * created schedule (incl. its id).
     *
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>
     */
    public function createSchedule(array $schedule): array
    {
        $data = $this->send('post', '/calendars/schedules', self::CALENDARS_VERSION, json: [
            ...$schedule,
            'locationId' => $this->locationId,
        ]);

        $result = $data['schedule'] ?? null;

        return is_array($result) ? $result : $data;
    }

    /**
     * Replace an existing availability schedule's rules/timezone/name.
     *
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>
     */
    public function updateSchedule(string $scheduleId, array $schedule): array
    {
        return $this->send('put', '/calendars/schedules/'.$scheduleId, self::CALENDARS_VERSION, json: $schedule);
    }

    /**
     * Read one availability schedule (rules, timezone, associations).
     *
     * @return array<string, mixed>
     */
    public function schedule(string $scheduleId): array
    {
        $data = $this->get('/calendars/schedules/'.$scheduleId, self::CALENDARS_VERSION);

        $result = $data['schedule'] ?? null;

        return is_array($result) ? $result : $data;
    }

    /**
     * All availability schedules for one user in the salon's location — lets
     * a sync ADOPT a schedule that already exists (a previous run whose id
     * never got stored, or one made by hand) instead of creating a twin.
     *
     * @return list<array<string, mixed>>
     */
    public function schedulesForUser(string $userId): array
    {
        $data = $this->get('/calendars/schedules/search', self::CALENDARS_VERSION, [
            'locationId' => $this->locationId,
            'userId' => $userId,
        ]);

        return array_values(array_filter((array) ($data['schedules'] ?? []), 'is_array'));
    }

    /**
     * Apply an availability schedule to a calendar, so the calendar's slot
     * search honours it for that user.
     */
    public function applyScheduleToCalendar(string $scheduleId, string $calendarId): void
    {
        $this->send('put', '/calendars/schedules/'.$scheduleId.'/associations/'.$calendarId, self::CALENDARS_VERSION, json: []);
    }

    /**
     * Update a calendar's settings (slot duration/interval, buffers, …).
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function updateCalendar(string $calendarId, array $settings): array
    {
        return $this->send('put', '/calendars/'.$calendarId, self::CALENDARS_VERSION, json: $settings);
    }

    /**
     * All calendar events (appointments) on one calendar in a time window —
     * the reconciliation feed. GET /calendars/events wants epoch-millisecond
     * strings for the window bounds (per the published OpenAPI spec).
     *
     * @return list<array<string, mixed>>
     */
    public function calendarEvents(string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $data = $this->get('/calendars/events', self::CALENDARS_VERSION, [
            'locationId' => $this->locationId,
            'calendarId' => $calendarId,
            'startTime' => (string) $from->getTimestampMs(),
            'endTime' => (string) $to->getTimestampMs(),
        ]);

        return array_values(array_filter((array) ($data['events'] ?? []), 'is_array'));
    }

    /**
     * One contact by id — used to enrich reconciliation-imported bookings
     * with a real name/email/phone (the events feed only carries contactId).
     *
     * @return array<string, mixed>
     */
    public function contact(string $contactId): array
    {
        $data = $this->get('/contacts/'.$contactId, self::CONTACTS_VERSION);

        $contact = $data['contact'] ?? null;

        return is_array($contact) ? $contact : [];
    }

    /**
     * Upsert a contact in the salon's location (matched server-side by
     * email/phone when present). Returns the contact array incl. its id.
     *
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    public function upsertContact(array $contact): array
    {
        $data = $this->send('post', '/contacts/upsert', self::CONTACTS_VERSION, json: [
            ...$contact,
            'locationId' => $this->locationId,
        ]);

        $result = $data['contact'] ?? null;

        return is_array($result) ? $result : [];
    }

    /**
     * Create an appointment on a calendar in the salon's location. Returns
     * the appointment array incl. its id.
     *
     * @param  array<string, mixed>  $appointment
     * @return array<string, mixed>
     */
    public function createAppointment(array $appointment): array
    {
        return $this->send('post', '/calendars/events/appointments', self::CALENDARS_VERSION, json: [
            ...$appointment,
            'locationId' => $this->locationId,
        ]);
    }

    /**
     * Update an existing appointment (times, provider, title, or an
     * appointmentStatus of "cancelled" to cancel it).
     *
     * @param  array<string, mixed>  $appointment
     * @return array<string, mixed>
     */
    public function updateAppointment(string $eventId, array $appointment): array
    {
        return $this->send('put', '/calendars/events/appointments/'.$eventId, self::CALENDARS_VERSION, json: $appointment);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, string $version, array $query = []): array
    {
        return $this->send('get', $path, $version, $query);
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, string $version, array $query = [], ?array $json = null): array
    {
        $this->throttle();

        try {
            $pending = $this->pending($version);

            $response = match ($method) {
                'get' => $pending->get($path, $query),
                'post' => $pending->post($path, $json ?? []),
                'put' => $pending->put($path, $json ?? []),
                default => throw new \InvalidArgumentException("Unsupported method [{$method}]."),
            };
        } catch (ConnectionException) {
            throw GhlApiException::network();
        }

        if ($response->failed()) {
            throw GhlApiException::fromStatus($response->status());
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
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

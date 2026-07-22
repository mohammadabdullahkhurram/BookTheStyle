<?php

namespace App\Services\Ghl;

use App\Actions\Salons\TestGhlConnection;
use App\Models\Salon;
use App\Models\StylistProfile;
use App\Support\AppHost;
use App\Support\PublicUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;

/**
 * On-demand verification for every GoHighLevel-facing integration — the
 * engine behind the "Test" / "Verify" buttons in Settings → Integrations and
 * the setup wizard. Each check runs a REAL read (or a self-cleaning
 * round-trip) through the same client/services production uses and answers
 * with a specific, human pass/fail message plus a likely fix.
 *
 * Safety: nothing here touches real bookings, availability or clients. The
 * booking round-trip creates ONE clearly-titled test appointment on a
 * far-future 3 AM slot and deletes it before reporting; the only contact it
 * writes is the salon's dedicated test contact. Checks are rate-limited per
 * salon, results never contain tokens or client PII, and URL-dependent
 * checks (webhook, booking API) report an honest BLOCKED state on a local
 * APP_URL instead of a false failure — they work unchanged once deployed.
 *
 * Results persist in salons.integration_checks (key → toArray()) so both
 * surfaces share one "Last verified X ago" history.
 */
class IntegrationChecks
{
    public const CONNECTION = 'connection';

    public const CONTACTS = 'contacts';

    public const MAPPING = 'mapping';

    public const AVAILABILITY = 'availability';

    public const BOOKING = 'booking';

    public const WEBHOOK = 'webhook';

    public const VOICE = 'voice';

    public const KEYS = [
        self::CONNECTION, self::CONTACTS, self::MAPPING, self::AVAILABILITY,
        self::BOOKING, self::WEBHOOK, self::VOICE,
    ];

    /** Runs allowed per salon per check per minute. */
    private const RATE_LIMIT = 6;

    /**
     * Run one check, persist its outcome on the salon, and return it.
     * Authorisation (SalonPolicy::manageGhlConnection) is the caller's job.
     */
    public function run(Salon $salon, string $key, #[SensitiveParameter] ?string $voiceToken = null): IntegrationCheckResult
    {
        if ($salon->is_demo) {
            throw new \RuntimeException('Demo salons cannot run integration checks.');
        }

        if (! in_array($key, self::KEYS, true)) {
            return IntegrationCheckResult::failed(__('Unknown check.'));
        }

        if (! RateLimiter::attempt("integration-check:{$salon->id}:{$key}", self::RATE_LIMIT, fn (): bool => true, 60)) {
            // Not persisted — a throttle notice must not overwrite a real result.
            return IntegrationCheckResult::failed(__('Too many runs — wait a minute, then test again.'));
        }

        $result = match ($key) {
            self::CONNECTION => $this->connection($salon),
            self::CONTACTS => $this->contacts($salon),
            self::MAPPING => $this->mapping($salon),
            self::AVAILABILITY => $this->availability($salon),
            self::BOOKING => $this->bookingRoundTrip($salon),
            self::WEBHOOK => $this->webhook($salon),
            self::VOICE => $this->voiceApi($salon, $voiceToken),
        };

        if ($result->state !== IntegrationCheckResult::BLOCKED) {
            $checks = (array) $salon->integration_checks;
            $checks[$key] = $result->toArray();
            $salon->integration_checks = $checks;
            $salon->save();
        }

        return $result;
    }

    /** The existing PIT check, folded into the shared result shape. */
    private function connection(Salon $salon): IntegrationCheckResult
    {
        $check = app(TestGhlConnection::class)->handle($salon);

        return $check->ok
            ? IntegrationCheckResult::passed($check->message)
            : IntegrationCheckResult::failed($check->message, __('Check the location ID and re-paste the private integration token if it was rotated.'));
    }

    /**
     * Contacts read + write with the PIT — proves the contacts.readonly and
     * contacts.write scopes respond before the sync ever needs them.
     */
    private function contacts(Salon $salon): IntegrationCheckResult
    {
        $connection = $salon->ghlConnection()->first();

        if ($connection === null || ! $connection->hasToken() || blank($connection->location_id)) {
            return IntegrationCheckResult::failed(__('Connect GoHighLevel first — the contact check needs the location ID and token.'));
        }

        $client = GhlClient::fromConnection($connection);
        $details = [];

        try {
            $client->contacts(1);
            $details[] = ['ok' => true, 'text' => __('Read contacts — OK (contacts.readonly)')];
        } catch (GhlApiException $e) {
            return IntegrationCheckResult::failed(
                __('Reading contacts failed — :reason', ['reason' => $e->getMessage()]),
                __('The token is probably missing the contacts.readonly scope — recreate the private integration with every required scope.'),
                [['ok' => false, 'text' => __('Read contacts — failed')]],
            );
        }

        try {
            $client->upsertContact(self::testContactPayload($salon));
            $details[] = ['ok' => true, 'text' => __('Write contacts — OK (contacts.write)')];
        } catch (GhlApiException $e) {
            $details[] = ['ok' => false, 'text' => __('Write contacts — failed')];

            return IntegrationCheckResult::failed(
                __('Writing a contact failed — :reason', ['reason' => $e->getMessage()]),
                __('The token is probably missing the contacts.write scope — recreate the private integration with every required scope.'),
                $details,
            );
        }

        return IntegrationCheckResult::passed(__('Contact sync ready — the token can both read and write contacts.'), $details);
    }

    /**
     * The configured master calendar exists (and is live) in GHL, and every
     * booking stylist maps to a REAL team member on that calendar — flagged
     * by name either way.
     */
    private function mapping(Salon $salon): IntegrationCheckResult
    {
        $connection = $salon->ghlConnection()->first();

        if ($connection === null || ! $connection->hasToken() || blank($connection->location_id)) {
            return IntegrationCheckResult::failed(__('Connect GoHighLevel first.'));
        }

        if (blank($connection->calendar_id)) {
            return IntegrationCheckResult::failed(
                __('No master calendar chosen yet.'),
                __('Pick the calendar under “Master calendar and staff mapping”, then verify again.'),
            );
        }

        try {
            $calendars = GhlClient::fromConnection($connection)->calendars();
        } catch (GhlApiException $e) {
            return IntegrationCheckResult::failed($e->getMessage(), __('Run “Test connection” first — the mapping check reads the calendar list with the same token.'));
        }

        $calendar = null;
        foreach ($calendars as $candidate) {
            if ($candidate->id === $connection->calendar_id) {
                $calendar = $candidate;
                break;
            }
        }

        if ($calendar === null) {
            return IntegrationCheckResult::failed(
                __('The configured master calendar was not found in GoHighLevel — it may have been deleted.'),
                __('Pick the calendar again under “Master calendar and staff mapping”.'),
            );
        }

        $details = [];
        $allLinked = true;

        if (! $calendar->isActive) {
            $allLinked = false;
            $details[] = ['ok' => false, 'text' => __('Calendar “:name” is inactive in GoHighLevel — activate it', ['name' => $calendar->name])];
        }

        $stylists = $salon->stylistUsers()->orderBy('name')->get(['users.id', 'name']);

        if ($stylists->isEmpty()) {
            return IntegrationCheckResult::failed(__('No active stylists yet — add stylists under Staff first.'));
        }

        $map = StylistProfile::forSalon($salon)
            ->whereNotNull('ghl_user_id')
            ->pluck('ghl_user_id', 'user_id');

        $linked = 0;
        foreach ($stylists as $stylist) {
            $ghlUserId = $map[$stylist->id] ?? null;

            if (blank($ghlUserId)) {
                $allLinked = false;
                $details[] = ['ok' => false, 'text' => __(':name is not mapped to a calendar provider yet', ['name' => $stylist->name])];
            } elseif (! in_array((string) $ghlUserId, $calendar->teamMemberIds, true)) {
                $allLinked = false;
                $details[] = ['ok' => false, 'text' => __(':name → the mapped user is not a team member on this calendar', ['name' => $stylist->name])];
            } else {
                $linked++;
                $details[] = ['ok' => true, 'text' => __(':name → linked OK', ['name' => $stylist->name])];
            }
        }

        if ($allLinked) {
            return IntegrationCheckResult::passed(
                __('Master calendar “:calendar” is live in GoHighLevel and all :count stylists are correctly linked.', ['calendar' => $calendar->name, 'count' => $stylists->count()]),
                $details,
            );
        }

        return IntegrationCheckResult::failed(
            __('Master calendar “:calendar” found — :linked of :count stylists correctly linked.', ['calendar' => $calendar->name, 'linked' => $linked, 'count' => $stylists->count()]),
            __('Add the flagged stylists as team members on the calendar in GoHighLevel, map them under “Master calendar and staff mapping”, then verify again.'),
            $details,
        );
    }

    /**
     * Read each mapped stylist's availability schedule BACK from GHL —
     * proves the mirror actually exists there, not just that our last push
     * claimed success.
     */
    private function availability(Salon $salon): IntegrationCheckResult
    {
        $connection = $salon->ghlConnection()->first();

        if ($connection === null || ! $connection->hasToken() || blank($connection->location_id)) {
            return IntegrationCheckResult::failed(__('Connect GoHighLevel first.'));
        }

        $profiles = StylistProfile::forSalon($salon)
            ->whereNotNull('ghl_user_id')
            ->with('user:id,name')
            ->get()
            ->sortBy(fn (StylistProfile $profile) => mb_strtolower((string) $profile->user?->name))
            ->values();

        if ($profiles->isEmpty()) {
            return IntegrationCheckResult::failed(
                __('No stylists are mapped to GoHighLevel providers yet.'),
                __('Map stylists under “Master calendar and staff mapping”, run the availability sync, then verify.'),
            );
        }

        $client = GhlClient::fromConnection($connection);
        $details = [];
        $present = 0;

        foreach ($profiles as $profile) {
            $name = $profile->user->name ?? __('Stylist');

            if (blank($profile->ghl_schedule_id)) {
                $details[] = ['ok' => false, 'text' => __(':name → never synced — run “Sync availability to GoHighLevel”', ['name' => $name])];

                continue;
            }

            try {
                $schedules = $client->schedulesForUser((string) $profile->ghl_user_id);
            } catch (GhlApiException $e) {
                return IntegrationCheckResult::failed(
                    __('Reading schedules from GoHighLevel failed — :reason', ['reason' => $e->getMessage()]),
                    __('Run “Test connection” first, then verify again.'),
                    $details,
                );
            }

            $ids = array_map(fn (array $schedule): string => (string) ($schedule['id'] ?? ''), $schedules);

            if (in_array((string) $profile->ghl_schedule_id, $ids, true)) {
                $present++;
                $details[] = ['ok' => true, 'text' => __(':name → schedule present in GoHighLevel', ['name' => $name])];
            } else {
                $details[] = ['ok' => false, 'text' => __(':name → the synced schedule no longer exists in GoHighLevel — re-run the sync', ['name' => $name])];
            }
        }

        if ($present === $profiles->count()) {
            return IntegrationCheckResult::passed(
                __('Every mapped stylist’s schedule was read back from GoHighLevel (:count of :count).', ['count' => $profiles->count()]),
                $details,
            );
        }

        return IntegrationCheckResult::failed(
            __(':present of :count mapped stylists have a live schedule in GoHighLevel.', ['present' => $present, 'count' => $profiles->count()]),
            __('Run “Sync availability to GoHighLevel” for the flagged stylists, then verify again.'),
            $details,
        );
    }

    /**
     * Outbound booking sync round trip: create ONE clearly-titled test
     * appointment through the same push path real bookings use, read it back,
     * then DELETE it. Non-destructive by design — far-future 3 AM slot, the
     * salon's dedicated test contact, no real client data, nothing left
     * behind on success.
     */
    private function bookingRoundTrip(Salon $salon): IntegrationCheckResult
    {
        $connection = $salon->ghlConnection()->first();

        if ($connection === null || ! $connection->isConnected()) {
            return IntegrationCheckResult::failed(__('Connect GoHighLevel and choose the master calendar first.'));
        }

        $profile = StylistProfile::forSalon($salon)->whereNotNull('ghl_user_id')->first();

        if ($profile === null) {
            return IntegrationCheckResult::failed(
                __('No stylist is mapped to a GoHighLevel provider yet.'),
                __('Map at least one stylist under “Master calendar and staff mapping” first.'),
            );
        }

        $client = GhlClient::fromConnection($connection);
        $details = [];

        try {
            $contact = $client->upsertContact(self::testContactPayload($salon));
        } catch (GhlApiException $e) {
            return IntegrationCheckResult::failed(
                __('Could not prepare the test contact — :reason', ['reason' => $e->getMessage()]),
                __('Check the contacts.write scope on the private integration.'),
            );
        }

        $contactId = $contact['id'] ?? null;

        if (! is_string($contactId) || $contactId === '') {
            return IntegrationCheckResult::failed(__('GoHighLevel accepted the test contact but returned no id — cannot continue safely.'));
        }

        $details[] = ['ok' => true, 'text' => __('Test contact ready')];

        // Far future, 3 AM, 15 minutes: never collides with anything real.
        $start = now($salon->timezone)->addYear()->startOfDay()->setTime(3, 0);

        try {
            $created = $client->createAppointment([
                'title' => self::TEST_APPOINTMENT_TITLE,
                'appointmentStatus' => 'confirmed',
                'assignedUserId' => (string) $profile->ghl_user_id,
                'calendarId' => (string) $connection->calendar_id,
                'startTime' => $start->format('Y-m-d\TH:i:sP'),
                'endTime' => $start->addMinutes(15)->format('Y-m-d\TH:i:sP'),
                'ignoreFreeSlotValidation' => true,
                'contactId' => $contactId,
            ]);
        } catch (GhlApiException $e) {
            return IntegrationCheckResult::failed(
                __('Creating the test appointment failed — :reason', ['reason' => $e->getMessage()]),
                __('Check the calendars/events.write scope and that the mapped provider is on the master calendar.'),
                $details,
            );
        }

        // Same defensive id extraction as the real pusher.
        $eventId = $created['id']
            ?? $created['appointmentId']
            ?? data_get($created, 'appointment.id')
            ?? data_get($created, 'event.id');

        if (! is_string($eventId) || $eventId === '' || $eventId === (string) $connection->calendar_id) {
            return IntegrationCheckResult::failed(
                __('The test appointment was created but GoHighLevel returned no usable id, so it could not be cleaned up.'),
                __('Delete the appointment titled “:title” manually in GoHighLevel.', ['title' => self::TEST_APPOINTMENT_TITLE]),
                $details,
            );
        }

        $details[] = ['ok' => true, 'text' => __('Created a test appointment through the real push path')];

        $readBack = false;
        try {
            $fetched = $client->appointment($eventId);
            $fetchedId = data_get($fetched, 'appointment.id') ?? data_get($fetched, 'event.id') ?? ($fetched['id'] ?? null);
            $readBack = $fetchedId === $eventId || $fetched !== [];
        } catch (GhlApiException) {
            // fall through — still attempt the delete below.
        }

        $details[] = ['ok' => $readBack, 'text' => $readBack
            ? __('Read it back from GoHighLevel')
            : __('Could not read it back from GoHighLevel')];

        try {
            $client->deleteEvent($eventId);
            $details[] = ['ok' => true, 'text' => __('Deleted it — nothing left behind')];
        } catch (GhlApiException $e) {
            $details[] = ['ok' => false, 'text' => __('Could not delete it')];

            return IntegrationCheckResult::failed(
                __('The round trip created the test appointment but could not delete it — :reason', ['reason' => $e->getMessage()]),
                __('Delete the appointment titled “:title” manually in GoHighLevel, then check the calendars/events.write scope.', ['title' => self::TEST_APPOINTMENT_TITLE]),
                $details,
            );
        }

        if (! $readBack) {
            return IntegrationCheckResult::failed(
                __('The test appointment was created and deleted, but could not be read back in between.'),
                __('Check the calendars/events.readonly scope, then run the test again.'),
                $details,
            );
        }

        return IntegrationCheckResult::passed(
            __('Round trip passed — a test appointment was created through the same push path real bookings use, read back from GoHighLevel, and deleted.'),
            $details,
        );
    }

    /**
     * The app pings its own PUBLIC webhook URL with the real secret and a
     * test payload the controller answers without recording an event —
     * proves reachability, routing and the secret in one shot.
     */
    private function webhook(Salon $salon): IntegrationCheckResult
    {
        $connection = $salon->ghlConnection()->first();

        if ($connection === null || blank($connection->webhook_secret)) {
            return IntegrationCheckResult::failed(__('Generate the webhook secret first.'));
        }

        // The webhook lives on the APP host, not the apex APP_URL — building
        // off app.url alone was the reset-email bug's twin. AppHost derives
        // scheme/port from app.url and the host from app.{APP_DOMAIN}.
        $url = AppHost::app('webhooks/ghl');

        if (! PublicUrl::isPublic($url)) {
            return IntegrationCheckResult::blocked(
                __('This check calls the webhook over its public URL, which GoHighLevel must be able to reach — a local address cannot be tested. It runs automatically once the app is deployed on its live URL.'),
            );
        }

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withHeaders(['X-Webhook-Secret' => (string) $connection->webhook_secret])
                ->post($url, [
                    'type' => 'bookthestyle.webhook.test',
                    'locationId' => (string) $connection->location_id,
                ]);
        } catch (ConnectionException) {
            return IntegrationCheckResult::failed(
                __('The webhook did not answer at :url.', ['url' => $url]),
                __('Is the app deployed and the domain pointing at it? Test again once the live URL responds.'),
            );
        }

        if ($response->status() === 200 && $response->json('test') === true) {
            return IntegrationCheckResult::passed(
                __('Delivered — the endpoint answered at its public URL and the secret verified. GoHighLevel can reach the app.'),
            );
        }

        if ($response->status() === 401) {
            return IntegrationCheckResult::failed(
                __('The endpoint is reachable, but the secret did not verify.'),
                __('Rotate the secret here and update the X-Webhook-Secret header in the GHL workflow to the new value.'),
            );
        }

        return IntegrationCheckResult::failed(
            __('Unexpected answer (:status) from :url.', ['status' => $response->status(), 'url' => $url]),
            __('Check that the live app is up to date and the URL is correct.'),
        );
    }

    /**
     * Call the salon's OWN booking API over the public URL — exactly what
     * the GHL voice custom action does. With the freshly generated token
     * still on screen it proves the full 200-with-slots path; without it
     * (the token is stored hashed) it proves the endpoint + auth stack by
     * expecting a clean 401 for a bad token.
     */
    private function voiceApi(Salon $salon, #[SensitiveParameter] ?string $plainToken): IntegrationCheckResult
    {
        if ($salon->api_token_hash === null) {
            return IntegrationCheckResult::failed(
                __('Generate the booking API token first.'),
                __('Generate it in this section, then run the test while the token is still on screen for a full end-to-end check.'),
            );
        }

        // Same host rule: the booking API lives on app.{APP_DOMAIN}.
        $url = AppHost::app('api/v1/booking/availability');

        if (! PublicUrl::isPublic($url)) {
            return IntegrationCheckResult::blocked(
                __('This check calls the booking API over the app’s public URL — the same way the GHL custom action does. A local address cannot be tested; it runs automatically once the app is deployed on its live URL.'),
            );
        }

        $serviceName = $salon->services()->where('active', true)->orderBy('name')->value('name');

        if ($plainToken !== null && ! is_string($serviceName)) {
            return IntegrationCheckResult::failed(
                __('Add at least one active service first — the end-to-end test asks for real availability.'),
            );
        }

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withToken($plainToken ?? 'bts-integration-check-invalid-token')
                ->post($url, ['service' => is_string($serviceName) ? $serviceName : 'test']);
        } catch (ConnectionException) {
            return IntegrationCheckResult::failed(
                __('The booking API did not answer at :url.', ['url' => $url]),
                __('Is the app deployed and the domain pointing at it?'),
            );
        }

        if ($plainToken !== null) {
            if ($response->status() === 200 && $response->json('success') === true) {
                $slots = is_array($response->json('slots')) ? count($response->json('slots')) : 0;

                return IntegrationCheckResult::passed(
                    __('200 OK — the booking API answered with :count open slots for “:service”. This is exactly what the GHL custom action receives.', ['count' => $slots, 'service' => (string) $serviceName]),
                );
            }

            if ($response->status() === 401) {
                return IntegrationCheckResult::failed(
                    __('The endpoint answered, but it rejected the token.'),
                    __('Regenerate the token and update the GHL custom action with the new value.'),
                );
            }

            return IntegrationCheckResult::failed(
                __('The endpoint answered :status — :message', ['status' => $response->status(), 'message' => (string) $response->json('message')]),
            );
        }

        // Probe mode: the stored token is hashed, so prove the endpoint +
        // auth stack with a deliberately invalid bearer instead.
        if ($response->status() === 401 && $response->json('error') === 'unauthenticated') {
            return IntegrationCheckResult::passed(
                __('The booking API is reachable at its public URL and correctly rejects bad tokens. The stored token is hashed, so its exact value can only be fully tested right after generating it — or from the GHL custom action itself.'),
            );
        }

        if ($response->status() === 404) {
            return IntegrationCheckResult::failed(
                __('404 — the booking API route was not found at :url.', ['url' => $url]),
                __('Check the app URL configuration on the deployed app.'),
            );
        }

        return IntegrationCheckResult::failed(
            __('Unexpected answer (:status) from :url.', ['status' => $response->status(), 'url' => $url]),
        );
    }

    public const TEST_APPOINTMENT_TITLE = 'BookTheStyle round-trip test — safe to delete';

    /**
     * The salon's dedicated, clearly-labelled test contact — the ONLY contact
     * any check ever writes. Upserts match it by its unique per-salon email,
     * so repeated runs reuse one record instead of multiplying.
     *
     * @return array<string, string>
     */
    private static function testContactPayload(Salon $salon): array
    {
        return [
            'name' => 'BookTheStyle Connection Test',
            'email' => 'integration-check+salon'.$salon->id.'@bookthestyle.app',
        ];
    }
}

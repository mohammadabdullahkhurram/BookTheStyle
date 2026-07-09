<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\Service;
use App\Models\StylistProfile;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Ghl\GhlBookingPusher;
use App\Services\Ghl\GhlInboundSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
| Phase 6c inbound: the /webhooks/ghl endpoint (secret verification, salon
| resolution, replay dedupe, fast ack + queued processing) and the inbound
| sync itself — echo suppression, last-change-wins, GHL-originated bookings,
| and tenant isolation. Sync queue driver: queued processing runs inline.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')); // Mon 08:00 EDT
});
afterEach(fn () => Carbon::setTestNow());

function whSalon(string $locationId = 'loc_1', string $secret = 'wh-secret-1'): Salon
{
    $salon = bookingSalon();
    $connection = SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => $locationId,
        'private_integration_token' => 'pit-secret',
        'calendar_id' => 'cal_master',
    ]);
    $connection->webhook_secret = $secret;
    $connection->save();

    return $salon;
}

function whStylist(Salon $salon, string $providerId = 'prov_anna'): User
{
    $stylist = stylistOf($salon);
    StylistProfile::updateOrCreate(
        ['salon_id' => $salon->id, 'user_id' => $stylist->id],
        ['ghl_user_id' => $providerId],
    );

    return $stylist;
}

/** A pushed one-item booking: slice ghl_a1, item 2026-06-23 10:00–10:45 ET. */
function whPushedBooking(Salon $salon, User $stylist): Booking
{
    $client = Client::factory()->for($salon)->create([
        'name' => 'Casey Client', 'email' => 'casey@example.com', 'ghl_contact_id' => 'ghl_c1',
    ]);
    $booking = Booking::factory()->for($salon)->for($client)->create(['status' => BookingStatus::Booked]);
    BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => Service::factory()->for($salon)->create(['name' => 'Cut & Style'])->id,
        'stylist_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2026-06-23 10:45', $salon->timezone),
    ]);

    Http::fake(['services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'ghl_a1'])]);
    app(GhlBookingPusher::class)->push($booking);

    return $booking->fresh();
}

/**
 * @param  array<string, mixed>  $appointment
 * @return array<string, mixed>
 */
function whPayload(array $appointment, array $extra = []): array
{
    return array_merge([
        'locationId' => 'loc_1',
        'appointment' => $appointment,
        'contact' => ['id' => 'ghl_c1', 'name' => 'Casey Client', 'email' => 'casey@example.com'],
    ], $extra);
}

function postWebhook(array $payload, string $secret = 'wh-secret-1')
{
    return test()->postJson(route('webhooks.ghl'), $payload, ['X-Webhook-Secret' => $secret]);
}

// ---------------------------------------------------------------------------
// Endpoint: verification + tenancy + replay
// ---------------------------------------------------------------------------

it('rejects a webhook with a missing or wrong secret', function () {
    whSalon();

    postWebhook(whPayload(['id' => 'x']), 'wrong-secret')->assertUnauthorized();
    test()->postJson(route('webhooks.ghl'), whPayload(['id' => 'x']))->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0);
});

it('rejects an unknown or missing location uniformly', function () {
    whSalon();

    postWebhook(whPayload(['id' => 'x'], ['locationId' => 'loc_nope']))->assertUnauthorized();
    postWebhook(['appointment' => ['id' => 'x']])->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0);
});

it('never lets one salon\'s secret authorize another salon\'s location', function () {
    whSalon('loc_1', 'wh-secret-1');
    whSalon('loc_2', 'wh-secret-2');

    // Salon 2's secret against salon 1's location: refused.
    postWebhook(whPayload(['id' => 'x']), 'wh-secret-2')->assertUnauthorized();
    expect(WebhookEvent::count())->toBe(0);
});

it('acks fast, logs the event, and drops exact replays', function () {
    $salon = whSalon();

    $payload = whPayload(['id' => 'ghl_unknown', 'appointmentStatus' => 'cancelled']);

    postWebhook($payload)->assertStatus(202);
    postWebhook($payload)->assertStatus(202);

    $events = WebhookEvent::where('salon_id', $salon->id)->orderBy('id')->get();
    expect($events)->toHaveCount(2);
    expect($events[1]->status)->toBe(WebhookEvent::STATUS_IGNORED_REPLAY);
});

it('flags an unparseable appointment payload for review instead of crashing', function () {
    $salon = whSalon();

    postWebhook(['locationId' => 'loc_1', 'unexpected' => ['shape' => true]])->assertStatus(202);

    expect(WebhookEvent::where('salon_id', $salon->id)->value('status'))->toBe(WebhookEvent::STATUS_REVIEW);
});

// ---------------------------------------------------------------------------
// Echo suppression (the critical part)
// ---------------------------------------------------------------------------

it('ignores the echo of our own push — no state flip, no re-push', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist);
    $updatedAtBefore = $booking->updated_at;

    // GHL echoes back exactly what we pushed (its dateUpdated is later).
    postWebhook(whPayload([
        'id' => 'ghl_a1',
        'appointmentStatus' => 'confirmed', // = toGhl(Booked)
        'startTime' => '2026-06-23T10:00:00-04:00',
        'endTime' => '2026-06-23T10:45:00-04:00',
        'dateUpdated' => '2026-06-22T12:00:05Z',
    ]))->assertStatus(202);

    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_IGNORED_ECHO);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Booked);
    expect($booking->updated_at->getTimestamp())->toBe($updatedAtBefore->getTimestamp());
    expect(Booking::where('salon_id', $salon->id)->count())->toBe(1); // no duplicate booking either

    // Only the original outbound push ever hit the API — the echo triggered
    // nothing outbound (a full app → GHL → echo round trip, one appointment).
    Http::assertSentCount(1);
});

// ---------------------------------------------------------------------------
// Genuine inbound changes
// ---------------------------------------------------------------------------

it('applies a genuine GHL cancellation to the app booking without re-pushing', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist);

    postWebhook(whPayload([
        'id' => 'ghl_a1',
        'appointmentStatus' => 'cancelled',
        'startTime' => '2026-06-23T10:00:00-04:00',
        'endTime' => '2026-06-23T10:45:00-04:00',
        'dateUpdated' => '2026-06-22T12:05:00Z',
    ]))->assertStatus(202);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->statusEvents()->latest('id')->first()->actor_user_id)->toBeNull();
    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_APPLIED);
    Http::assertSentCount(1); // still only the original outbound create
});

it('applies a genuine GHL reschedule to the app item times', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist);

    postWebhook(whPayload([
        'id' => 'ghl_a1',
        'appointmentStatus' => 'confirmed',
        'startTime' => '2026-06-23T14:00:00-04:00', // moved four hours later
        'endTime' => '2026-06-23T14:45:00-04:00',
        'dateUpdated' => '2026-06-22T12:05:00Z',
    ]))->assertStatus(202);

    $item = $booking->items()->first();
    expect($item->starts_at->setTimezone($salon->timezone)->format('Y-m-d H:i'))->toBe('2026-06-23 14:00');
    expect($item->ends_at->setTimezone($salon->timezone)->format('Y-m-d H:i'))->toBe('2026-06-23 14:45');
    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_APPLIED);

    // The follow-up echo of the applied change is recognised as our state.
    postWebhook(whPayload([
        'id' => 'ghl_a1',
        'appointmentStatus' => 'confirmed',
        'startTime' => '2026-06-23T14:00:00-04:00',
        'endTime' => '2026-06-23T14:45:00-04:00',
        'dateUpdated' => '2026-06-22T12:06:00Z',
    ]))->assertStatus(202);
    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_IGNORED_ECHO);
});

// ---------------------------------------------------------------------------
// Last-change-wins
// ---------------------------------------------------------------------------

it('re-pushes the app state when the app changed more recently than the inbound event', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist); // updated_at = frozen now

    // A stale GHL change from an hour ago, different time than the app's.
    postWebhook(whPayload([
        'id' => 'ghl_a1',
        'appointmentStatus' => 'confirmed',
        'startTime' => '2026-06-23T09:00:00-04:00',
        'endTime' => '2026-06-23T09:45:00-04:00',
        'dateUpdated' => '2026-06-22T11:00:00Z', // OLDER than the booking
    ]))->assertStatus(202);

    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_IGNORED_STALE);

    // The app's state was NOT changed…
    $item = $booking->items()->first();
    expect($item->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('10:00');

    // …and the app corrected GHL with a re-push of its own state.
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/events/appointments/ghl_a1')
        && $r['startTime'] === '2026-06-23T10:00:00-04:00');
});

it('applies the inbound change when GHL changed more recently', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist);

    postWebhook(whPayload([
        'id' => 'ghl_a1',
        'appointmentStatus' => 'noshow',
        'startTime' => '2026-06-23T10:00:00-04:00',
        'endTime' => '2026-06-23T10:45:00-04:00',
        'dateUpdated' => '2026-06-22T12:30:00Z', // NEWER than the booking
    ]))->assertStatus(202);

    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow);
    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_APPLIED);
});

// ---------------------------------------------------------------------------
// GHL-originated bookings
// ---------------------------------------------------------------------------

it('creates an app booking from a new GHL appointment, fully mapped', function () {
    $salon = whSalon();
    $stylist = whStylist($salon, 'prov_anna');
    Service::factory()->for($salon)->create(['name' => 'Cut & Style', 'duration_min' => 45]);
    $client = Client::factory()->for($salon)->create(['name' => 'Casey Client', 'ghl_contact_id' => 'ghl_c77']);

    postWebhook(whPayload([
        'id' => 'ghl_new1',
        'calendarId' => 'cal_master',
        'assignedUserId' => 'prov_anna',
        'appointmentStatus' => 'confirmed',
        'startTime' => '2026-06-23T15:00:00-04:00',
        'endTime' => '2026-06-23T16:00:00-04:00',
        'title' => 'Casey Client — Cut & Style',
        'dateUpdated' => '2026-06-22T12:05:00Z',
    ], [
        'contact' => ['id' => 'ghl_c77', 'name' => 'Casey Client'],
        'customData' => ['source' => 'voice_ai'],
    ]))->assertStatus(202);

    $booking = Booking::where('salon_id', $salon->id)->latest('id')->first();
    expect($booking)->not->toBeNull();
    expect($booking->client_id)->toBe($client->id);                       // matched by ghl_contact_id
    expect($booking->source)->toBe(BookingSource::VoiceAi);               // 6d source tagging hook
    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect($booking->items()->first()->stylist_id)->toBe($stylist->id);   // reverse provider mapping
    expect($booking->items()->first()->service->name)->toBe('Cut & Style'); // matched from title
    expect($booking->items()->first()->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('15:00');

    expect($booking->ghl_appointment_id)->toBe('ghl_new1');
    expect($booking->ghl_sync_status)->toBe('synced');
    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_CREATED_BOOKING);
});

it('falls back to the import service and a created client when nothing matches', function () {
    $salon = whSalon();
    whStylist($salon, 'prov_anna');

    postWebhook(whPayload([
        'id' => 'ghl_new2',
        'assignedUserId' => 'prov_anna',
        'appointmentStatus' => 'new',
        'startTime' => '2026-06-23T15:00:00-04:00',
        'endTime' => '2026-06-23T15:30:00-04:00',
        'title' => 'Something GHL made up',
    ], [
        'contact' => ['id' => 'ghl_c88', 'name' => 'New Person', 'email' => 'new@example.com'],
    ]))->assertStatus(202);

    $booking = Booking::where('salon_id', $salon->id)->latest('id')->first();
    expect($booking->source)->toBe(BookingSource::GhlManual);
    expect($booking->client->name)->toBe('New Person');
    expect($booking->client->ghl_contact_id)->toBe('ghl_c88');

    $service = $booking->items()->first()->service;
    expect($service->name)->toBe(GhlInboundSync::IMPORT_SERVICE_NAME);
    expect($service->active)->toBeFalse(); // never bookable in-app
});

it('flags an unmapped provider for review instead of dropping the booking', function () {
    $salon = whSalon();

    postWebhook(whPayload([
        'id' => 'ghl_new3',
        'assignedUserId' => 'prov_never_mapped',
        'appointmentStatus' => 'confirmed',
        'startTime' => '2026-06-23T15:00:00-04:00',
    ]))->assertStatus(202);

    expect(Booking::where('salon_id', $salon->id)->count())->toBe(0);
    $event = WebhookEvent::latest('id')->first();
    expect($event->status)->toBe(WebhookEvent::STATUS_REVIEW);
    expect($event->note)->toContain('not mapped');
});

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

it('never lets salon B\'s webhook touch salon A\'s booking', function () {
    $salonA = whSalon('loc_1', 'wh-secret-1');
    $stylistA = whStylist($salonA);
    $bookingA = whPushedBooking($salonA, $stylistA);

    whSalon('loc_2', 'wh-secret-2');

    // Salon B legitimately posts, referencing salon A's appointment id.
    postWebhook(whPayload([
        'id' => 'ghl_a1',
        'appointmentStatus' => 'cancelled',
        'startTime' => '2026-06-23T10:00:00-04:00',
        'dateUpdated' => '2026-06-22T12:30:00Z',
    ], ['locationId' => 'loc_2']), 'wh-secret-2')->assertStatus(202);

    // Salon A's booking is untouched; B's event resolved inside B only.
    expect($bookingA->fresh()->status)->toBe(BookingStatus::Booked);
    expect(WebhookEvent::latest('id')->first()->salon_id)->not->toBe($salonA->id);
});

// ---------------------------------------------------------------------------
// Real GHL workflow payload shape (nested calendar.*, misspelled
// appoinmentStatus, offset-less local times + selectedTimezone)
// ---------------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function realGhlPayload(array $calendar, array $extra = []): array
{
    return array_merge([
        'locationId' => 'loc_1',
        'contact_id' => 'ghl_c1',
        'email' => 'casey@example.com',
        'phone' => '+15550001111',
        'user' => ['firstName' => 'Abdullah', 'lastName' => 'Stylist Two', 'email' => 'abdullah@bluejaypro.com'],
        'calendar' => array_merge([
            'appointmentId' => 'ghl_a1',
            'id' => 'cal_master',              // the CALENDAR id — never the appointment
            'calendarName' => 'Schedule an Appointment',
            'selectedTimezone' => 'America/New_York',
            'status' => 'booked',              // stale field — must NOT be used
        ], $calendar),
    ], $extra);
}

it('cancels the app booking from a real GHL payload, reading appoinmentStatus not status', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist);

    postWebhook(realGhlPayload([
        'appoinmentStatus' => 'cancelled', // GHL's misspelling — the LIVE status
        'startTime' => '2026-06-23T10:00:00', // local wall clock, no offset
        'endTime' => '2026-06-23T10:45:00',
    ]))->assertStatus(202);

    // Had the handler read calendar.status ("booked"), nothing would change.
    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_APPLIED);
    Http::assertSentCount(1); // no outbound re-push of the inbound cancel
});

it('applies a real-shape reschedule DST-safely across timezones', function () {
    $salon = whSalon(); // salon tz America/New_York
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist);

    // The GHL calendar reports Los Angeles wall-clock: 16:30 PT = 19:30 ET.
    postWebhook(realGhlPayload([
        'appoinmentStatus' => 'confirmed',
        'startTime' => '2026-06-23T16:30:00',
        'endTime' => '2026-06-23T17:00:00',
        'selectedTimezone' => 'America/Los_Angeles',
    ]))->assertStatus(202);

    $item = $booking->items()->first();
    expect($item->starts_at->utc()->toIso8601ZuluString())->toBe('2026-06-23T23:30:00Z'); // 16:30 PDT
    expect($item->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('19:30');
    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_APPLIED);
});

it('treats a real-shape echo as an echo (status field noise ignored)', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist);

    postWebhook(realGhlPayload([
        'appoinmentStatus' => 'confirmed', // = toGhl(Booked): our own push echoed
        'startTime' => '2026-06-23T10:00:00',
        'endTime' => '2026-06-23T10:45:00',
    ]))->assertStatus(202);

    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_IGNORED_ECHO);
    expect($booking->fresh()->status)->toBe(BookingStatus::Booked);
    Http::assertSentCount(1); // still only the original outbound create
});

it('falls back to contact + start-time matching when the appointment id is unknown', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);

    // A booking that never reached GHL (no appointment id stored).
    $client = Client::factory()->for($salon)->create(['name' => 'Casey Client', 'ghl_contact_id' => 'ghl_c1']);
    $booking = Booking::factory()->for($salon)->for($client)->create(['status' => BookingStatus::Booked]);
    BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => Service::factory()->for($salon)->create()->id,
        'stylist_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2026-06-23 10:45', $salon->timezone),
    ]);

    postWebhook(realGhlPayload([
        'appointmentId' => 'ghl_from_ghl_side',
        'appoinmentStatus' => 'cancelled',
        'startTime' => '2026-06-23T10:00:00',
        'endTime' => '2026-06-23T10:45:00',
    ]))->assertStatus(202);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->ghl_appointment_id)->toBe('ghl_from_ghl_side'); // adopted
});

it('logs a clear no-match when neither id nor contact resolves', function () {
    Log::spy();
    $salon = whSalon();

    postWebhook(realGhlPayload([
        'appointmentId' => 'ghl_total_stranger',
        'appoinmentStatus' => 'confirmed',
        'startTime' => '2026-06-23T10:00:00',
    ], ['contact_id' => 'ghl_nobody', 'email' => 'nobody@example.com', 'phone' => '+10000000000']))
        ->assertStatus(202);

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message) => $message === 'GHL inbound: no matching booking')
        ->once();
});

// ---------------------------------------------------------------------------
// Appointment-id matching: calendar.appointmentId is unique; calendar.id is
// the SHARED calendar ref (identical across appointments) and must never be
// the match key.
// ---------------------------------------------------------------------------

it('cancels the correct bookings when two appointments share calendar.id but differ in appointmentId', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);

    // Two pushed bookings, storing the two REAL unique appointment ids.
    $client = Client::factory()->for($salon)->create(['name' => 'Casey Client', 'ghl_contact_id' => 'ghl_c1']);
    $make = function (string $ghlId, string $time) use ($salon, $stylist, $client) {
        $booking = Booking::factory()->for($salon)->for($client)->create([
            'status' => BookingStatus::Booked, 'ghl_appointment_id' => $ghlId,
        ]);
        BookingItem::factory()->create([
            'salon_id' => $salon->id, 'booking_id' => $booking->id,
            'service_id' => Service::factory()->for($salon)->create()->id,
            'stylist_id' => $stylist->id,
            'starts_at' => CarbonImmutable::parse($time, $salon->timezone),
            'ends_at' => CarbonImmutable::parse($time, $salon->timezone)->addMinutes(45),
        ]);

        return $booking;
    };
    $dying = $make('pffmwYRLztkTHfoTIX89', '2026-06-23 10:00');
    $extensions = $make('DQULILDja18MLO3mwNdP', '2026-06-23 14:00');

    // Cancel appt A — same shared calendar.id on both payloads.
    postWebhook(realGhlPayload([
        'appointmentId' => 'pffmwYRLztkTHfoTIX89',
        'id' => 'yxNfNassZfX6qtUPUgug',
        'appoinmentStatus' => 'cancelled',
        'startTime' => '2026-06-23T10:00:00',
    ]))->assertStatus(202);

    expect($dying->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($extensions->fresh()->status)->toBe(BookingStatus::Booked); // untouched

    // Cancel appt B.
    postWebhook(realGhlPayload([
        'appointmentId' => 'DQULILDja18MLO3mwNdP',
        'id' => 'yxNfNassZfX6qtUPUgug',
        'appoinmentStatus' => 'cancelled',
        'startTime' => '2026-06-23T14:00:00',
    ]))->assertStatus(202);

    expect($extensions->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('stores an id on outbound create that the webhook appointmentId then matches (round trip)', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist); // create response id => 'ghl_a1'

    // Outbound stored exactly the id the webhook sends as appointmentId.
    expect($booking->ghl_appointment_id)->toBe('ghl_a1');

    postWebhook(realGhlPayload([
        'appointmentId' => 'ghl_a1',
        'id' => 'yxNfNassZfX6qtUPUgug',
        'appoinmentStatus' => 'cancelled',
        'startTime' => '2026-06-23T10:00:00',
        'endTime' => '2026-06-23T10:45:00',
    ]))->assertStatus(202);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    Http::assertSentCount(1); // inbound apply never re-pushes
});

it('heals a booking poisoned with the shared calendar id via contact + time, correcting the id', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);

    // The historical bug stored calendar.id as the appointment id.
    $client = Client::factory()->for($salon)->create(['name' => 'Casey Client', 'ghl_contact_id' => 'ghl_c1']);
    $booking = Booking::factory()->for($salon)->for($client)->create([
        'status' => BookingStatus::Booked,
        'ghl_appointment_id' => 'yxNfNassZfX6qtUPUgug', // the SHARED calendar id
    ]);
    BookingItem::factory()->create([
        'salon_id' => $salon->id, 'booking_id' => $booking->id,
        'service_id' => Service::factory()->for($salon)->create()->id,
        'stylist_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2026-06-23 10:45', $salon->timezone),
    ]);

    postWebhook(realGhlPayload([
        'appointmentId' => 'pffmwYRLztkTHfoTIX89',
        'id' => 'yxNfNassZfX6qtUPUgug',
        'appoinmentStatus' => 'cancelled',
        'startTime' => '2026-06-23T10:00:00',
    ]))->assertStatus(202);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->ghl_appointment_id)->toBe('pffmwYRLztkTHfoTIX89'); // corrected
});

it('never stores the calendar id from an outbound create response, and warns instead', function () {
    Log::spy();
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'ghl_c1']]),
        // A degenerate response: only the calendar id, no appointment id.
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'cal_master']),
    ]);
    $salon = whSalon();
    $stylist = whStylist($salon);

    $client = Client::factory()->for($salon)->create(['name' => 'Casey Client']);
    $booking = Booking::factory()->for($salon)->for($client)->create(['status' => BookingStatus::Booked]);
    BookingItem::factory()->create([
        'salon_id' => $salon->id, 'booking_id' => $booking->id,
        'service_id' => Service::factory()->for($salon)->create()->id,
        'stylist_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2026-06-23 10:45', $salon->timezone),
    ]);

    app(GhlBookingPusher::class)->push($booking);

    expect($booking->fresh()->ghl_appointment_id)->toBeNull(); // calendar id refused
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => $message === 'GHL create returned no usable appointment id')
        ->once();
});

// ---------------------------------------------------------------------------
// Replay dedupe must never deadlock a legitimate re-delivery
// ---------------------------------------------------------------------------

it('processes an identical cancel body whose earlier twin failed — THE deadlock case', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist); // stores ghl_a1, status booked

    $cancel = realGhlPayload([
        'appointmentId' => 'ghl_a1',
        'appoinmentStatus' => 'cancelled',
        'startTime' => '2026-06-23T10:00:00',
        'endTime' => '2026-06-23T10:45:00',
    ]);

    // A past delivery of the SAME body that ended badly (processed under an
    // old bug) — previously this hash poisoned all future deliveries.
    WebhookEvent::create([
        'salon_id' => $salon->id,
        'event_type' => 'appointment',
        'payload' => $cancel,
        'payload_hash' => hash('sha256', json_encode($cancel) ?: ''),
        'status' => WebhookEvent::STATUS_REVIEW,
        'processed_at' => now()->subMinutes(30),
    ]);

    postWebhook($cancel)->assertStatus(202);

    // The re-delivery is processed, not dropped as a replay.
    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_APPLIED);
    Http::assertSentCount(1); // and still no outbound re-push
});

it('still drops an identical body right after a successful processing', function () {
    $salon = whSalon();
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist);

    $cancel = realGhlPayload([
        'appointmentId' => 'ghl_a1',
        'appoinmentStatus' => 'cancelled',
        'startTime' => '2026-06-23T10:00:00',
        'endTime' => '2026-06-23T10:45:00',
    ]);

    postWebhook($cancel)->assertStatus(202); // applied
    postWebhook($cancel)->assertStatus(202); // immediate duplicate → replay

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    $statuses = WebhookEvent::where('salon_id', $salon->id)->orderBy('id')->pluck('status');
    expect($statuses->last())->toBe(WebhookEvent::STATUS_IGNORED_REPLAY);
});

it('logs an unknown appointment status instead of silently no-opping', function () {
    Log::spy();
    $salon = whSalon();
    $stylist = whStylist($salon);
    $booking = whPushedBooking($salon, $stylist);

    postWebhook(realGhlPayload([
        'appointmentId' => 'ghl_a1',
        'appoinmentStatus' => 'somethingweird',
        'startTime' => '2026-06-23T10:00:00',
        'endTime' => '2026-06-23T10:45:00',
    ]))->assertStatus(202);

    expect($booking->fresh()->status)->toBe(BookingStatus::Booked); // unchanged, but…
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => $message === 'GHL inbound: unknown appointment status')
        ->once();
});

it('emits a structured decision log for every known-appointment event', function () {
    Log::spy();
    $salon = whSalon();
    $stylist = whStylist($salon);
    whPushedBooking($salon, $stylist);

    postWebhook(realGhlPayload([
        'appointmentId' => 'ghl_a1',
        'appoinmentStatus' => 'cancelled',
        'startTime' => '2026-06-23T10:00:00',
        'endTime' => '2026-06-23T10:45:00',
    ]))->assertStatus(202);

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context = []) => $message === 'GHL inbound decision'
            && ($context['decision'] ?? null) === 'applied'
            && ($context['appointment_id'] ?? null) === 'ghl_a1')
        ->once();
});

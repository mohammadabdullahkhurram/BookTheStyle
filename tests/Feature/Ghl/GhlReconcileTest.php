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
use App\Models\WebhookEvent;
use App\Services\Ghl\GhlBookingPusher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
| Phase 6d reconciliation: ghl:reconcile pulls each connected salon's GHL
| calendar events and repairs drift the webhooks missed — applying missed
| changes through the same inbound pipeline (echo suppression and
| last-change-wins included), importing unknown appointments with a source,
| and flagging bookings whose GHL appointment vanished. Idempotent.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')); // Mon 08:00 EDT
});
afterEach(fn () => Carbon::setTestNow());

function rcSalon(): Salon
{
    $salon = bookingSalon();
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_rc',
        'private_integration_token' => 'pit-secret',
        'calendar_id' => 'cal_master',
    ]);

    $stylist = stylistOf($salon);
    StylistProfile::updateOrCreate(
        ['salon_id' => $salon->id, 'user_id' => $stylist->id],
        ['ghl_user_id' => 'prov_rc'],
    );

    return $salon;
}

/** A pushed booking mirrored as appointment ghl_r1, Tue 2026-06-23 10:00–10:45 ET. */
function rcPushedBooking(Salon $salon): Booking
{
    $client = Client::factory()->for($salon)->create([
        'name' => 'Ren Client', 'email' => 'ren@example.com', 'ghl_contact_id' => 'ghl_rc1',
    ]);
    $booking = Booking::factory()->for($salon)->for($client)->create(['status' => BookingStatus::Booked]);
    BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => Service::factory()->for($salon)->create(['name' => 'Cut & Style'])->id,
        'stylist_id' => StylistProfile::forSalon($salon)->first()->user_id,
        'starts_at' => CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2026-06-23 10:45', $salon->timezone),
    ]);

    Http::fake(['services.leadconnectorhq.com/contacts/*/tags' => Http::response([]), 'services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'ghl_r1'])]);
    app(GhlBookingPusher::class)->push($booking);

    return $booking->fresh();
}

/** Fake the events-feed pull (and optionally a contact lookup). */
function rcFeed(array $events, array $contacts = []): void
{
    $fakes = [
        'services.leadconnectorhq.com/contacts/*/tags' => Http::response([]),
        'services.leadconnectorhq.com/calendars/events?*' => Http::response(['events' => $events]),
    ];
    foreach ($contacts as $id => $contact) {
        $fakes['services.leadconnectorhq.com/contacts/'.$id] = Http::response(['contact' => $contact]);
    }
    Http::fake($fakes);
}

/** The mirrored booking's own state, as the events feed would report it. */
function rcMatchingEvent(array $overrides = []): array
{
    return array_merge([
        'id' => 'ghl_r1',
        'calendarId' => 'cal_master',
        'contactId' => 'ghl_rc1',
        'assignedUserId' => 'prov_rc',
        'appointmentStatus' => 'confirmed',
        'startTime' => '2026-06-23T10:00:00-04:00',
        'endTime' => '2026-06-23T10:45:00-04:00',
        'dateUpdated' => '2026-06-22T07:59:00-04:00',
        'title' => 'Ren Client — Cut & Style',
    ], $overrides);
}

it('applies a status change made in GHL that the webhook missed', function () {
    $salon = rcSalon();
    $booking = rcPushedBooking($salon);

    rcFeed([rcMatchingEvent([
        'appointmentStatus' => 'cancelled',
        'dateUpdated' => '2026-06-22T08:30:00-04:00', // after the app's last edit
    ])]);

    test()->artisan('ghl:reconcile')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);

    $event = WebhookEvent::latest('id')->first();
    expect($event->event_type)->toBe('ghl.reconcile');
    expect($event->status)->toBe(WebhookEvent::STATUS_APPLIED);
});

it('applies a reschedule made in GHL that the webhook missed', function () {
    $salon = rcSalon();
    $booking = rcPushedBooking($salon);

    rcFeed([rcMatchingEvent([
        'startTime' => '2026-06-23T13:00:00-04:00',
        'endTime' => '2026-06-23T13:45:00-04:00',
        'dateUpdated' => '2026-06-22T08:30:00-04:00',
    ])]);

    test()->artisan('ghl:reconcile')->assertSuccessful();

    $start = $booking->fresh()->items()->min('starts_at');
    expect(CarbonImmutable::parse($start)->setTimezone($salon->timezone)->format('H:i'))->toBe('13:00');
});

it('creates an app booking, with a source, for a GHL appointment the app never saw', function () {
    $salon = rcSalon();

    rcFeed([[
        'id' => 'ghl_r_new',
        'calendarId' => 'cal_master',
        'contactId' => 'ghl_c_new',
        'assignedUserId' => 'prov_rc',
        'appointmentStatus' => 'confirmed',
        'startTime' => '2026-06-24T14:00:00-04:00',
        'endTime' => '2026-06-24T15:00:00-04:00',
        'dateUpdated' => '2026-06-22T08:30:00-04:00',
        'title' => 'Booked by the voice agent',
        'createdBy' => ['source' => 'third_party'],
    ]], contacts: [
        'ghl_c_new' => ['name' => 'Nadia New', 'email' => 'nadia@example.com', 'tags' => ['voice-ai']],
    ]);

    test()->artisan('ghl:reconcile')->assertSuccessful();

    $booking = Booking::where('salon_id', $salon->id)->where('ghl_appointment_id', 'ghl_r_new')->first();
    expect($booking)->not->toBeNull();
    expect($booking->source)->toBe(BookingSource::VoiceAi); // from the contact's tag
    expect($booking->client->name)->toBe('Nadia New');      // enriched via the contact lookup
    expect($booking->client->ghl_contact_id)->toBe('ghl_c_new');
    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_CREATED_BOOKING);
});

it('flags a booking whose GHL appointment vanished', function () {
    $salon = rcSalon();
    $booking = rcPushedBooking($salon);

    // Past the in-flight grace period; the feed no longer has ghl_r1.
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:20:00', 'UTC'));
    rcFeed([]);

    test()->artisan('ghl:reconcile')->assertSuccessful();

    $booking->refresh();
    expect($booking->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_FAILED);
    expect($booking->ghl_sync_error)->toContain('no longer exists');
    expect($booking->status)->toBe(BookingStatus::Booked); // flagged, never mutated
});

it('does not mistake an in-flight push for a vanished appointment', function () {
    $salon = rcSalon();
    $booking = rcPushedBooking($salon); // updated seconds ago

    rcFeed([]);

    test()->artisan('ghl:reconcile')->assertSuccessful();

    expect($booking->fresh()->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_SYNCED);
});

it('treats a feed that matches the app state as an echo: nothing written, no event rows', function () {
    $salon = rcSalon();
    $booking = rcPushedBooking($salon);
    $updatedAt = $booking->updated_at;

    rcFeed([rcMatchingEvent()]);

    test()->artisan('ghl:reconcile')->assertSuccessful();

    expect(WebhookEvent::count())->toBe(0); // pre-filtered — no synthetic event needed
    expect($booking->fresh()->status)->toBe(BookingStatus::Booked);
    expect($booking->fresh()->updated_at->getTimestamp())->toBe($updatedAt->getTimestamp());
});

it('respects last-change-wins: an older GHL state loses and the app re-pushes', function () {
    $salon = rcSalon();
    $booking = rcPushedBooking($salon);
    $booking->touch(); // the app edited at 12:00

    rcFeed([rcMatchingEvent([
        'appointmentStatus' => 'cancelled',
        'dateUpdated' => '2026-06-22T07:00:00-04:00', // BEFORE the app's edit
    ])]);
    Http::fake(['services.leadconnectorhq.com/contacts/*/tags' => Http::response([]), 'services.leadconnectorhq.com/calendars/events/appointments/*' => Http::response(['id' => 'ghl_r1'])]);

    test()->artisan('ghl:reconcile')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Booked); // stale cancel rejected
    expect(WebhookEvent::latest('id')->value('status'))->toBe(WebhookEvent::STATUS_IGNORED_STALE);
});

it('is idempotent: a second run changes nothing more', function () {
    $salon = rcSalon();
    $booking = rcPushedBooking($salon);

    rcFeed([rcMatchingEvent([
        'appointmentStatus' => 'cancelled',
        'dateUpdated' => '2026-06-22T08:30:00-04:00',
    ])]);

    test()->artisan('ghl:reconcile')->assertSuccessful();
    test()->artisan('ghl:reconcile')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect(Booking::where('salon_id', $salon->id)->count())->toBe(1);           // nothing duplicated
    expect(WebhookEvent::where('event_type', 'ghl.reconcile')->count())->toBe(1); // second run pre-filtered
});

it('never reconciles one salon\'s appointments into another salon', function () {
    $salonA = rcSalon();
    $bookingA = rcPushedBooking($salonA);

    // Salon B: connected, no bookings — its feed claims A's appointment id.
    $salonB = bookingSalon();
    SalonGhlConnection::factory()->for($salonB)->create([
        'location_id' => 'loc_rc_b',
        'private_integration_token' => 'pit-secret-b',
        'calendar_id' => 'cal_master_b',
    ]);

    rcFeed([rcMatchingEvent([
        'appointmentStatus' => 'cancelled',
        'dateUpdated' => '2026-06-22T08:30:00-04:00',
    ])], contacts: [
        'ghl_rc1' => ['name' => 'Ren Client', 'email' => 'ren@example.com'],
    ]);

    test()->artisan('ghl:reconcile', ['salon' => $salonB->id])->assertSuccessful();

    // A's booking is untouched: B's reconcile can only see B's bookings, and
    // the unmapped provider leaves the alien appointment flagged for review.
    expect($bookingA->fresh()->status)->toBe(BookingStatus::Booked);
    expect(Booking::where('salon_id', $salonB->id)->count())->toBe(0);
});

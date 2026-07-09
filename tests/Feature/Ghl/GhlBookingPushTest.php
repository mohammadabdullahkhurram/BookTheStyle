<?php

use App\Actions\Bookings\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\Service;
use App\Models\StylistProfile;
use App\Models\User;
use App\Services\Ghl\GhlApiException;
use App\Services\Ghl\GhlBookingPusher;
use App\Services\Ghl\GhlClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
| Phase 6b: queued push of app bookings to GHL. The booking is the source of
| truth — it always succeeds instantly; the mirror happens after commit via
| the queue (sync driver in tests, so dispatches run inline unless faked).
| All GHL HTTP is faked.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')); // Mon 08:00 EDT
});
afterEach(fn () => Carbon::setTestNow());

/** A booking-ready salon with a complete, mapped GHL connection. */
function ghlBookingSalon(): Salon
{
    $salon = bookingSalon();
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_1',
        'private_integration_token' => 'pit-secret',
        'calendar_id' => 'cal_master',
    ]);

    return $salon;
}

function mapProvider(Salon $salon, User $stylist, string $providerId = 'prov_1'): void
{
    StylistProfile::updateOrCreate(
        ['salon_id' => $salon->id, 'user_id' => $stylist->id],
        ['ghl_user_id' => $providerId],
    );
}

function fakeGhlPush(): void
{
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['new' => true, 'contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'ghl_a1', 'calendarId' => 'cal_master']),
    ]);
}

/** A factory-built booking (skips the create action) with one 10:00–11:00 item. */
function pushableBooking(Salon $salon, User $stylist, CarbonImmutable $start, array $attrs = []): Booking
{
    $client = Client::factory()->for($salon)->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
    $service = Service::factory()->for($salon)->create(['name' => 'Cut & Style']);

    $booking = Booking::factory()->for($salon)->for($client)->create(array_merge([
        'status' => BookingStatus::Booked,
    ], $attrs));

    BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => $service->id,
        'stylist_id' => $stylist->id,
        'starts_at' => $start,
        'ends_at' => $start->addHour(),
    ]);

    return $booking;
}

// ---------------------------------------------------------------------------
// Trigger points
// ---------------------------------------------------------------------------

it('queues a sync job on creation and again on cancellation', function () {
    Queue::fake();
    $salon = ghlBookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    mapProvider($salon, $stylist);

    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60));

    Queue::assertPushed(SyncBookingToGhl::class, fn (SyncBookingToGhl $job) => $job->bookingId === $booking->id);

    app(TransitionBookingStatus::class)->handle($owner, $salon, $booking, BookingStatus::Cancelled);

    Queue::assertPushed(SyncBookingToGhl::class, 2);
});

// ---------------------------------------------------------------------------
// Create: contact upsert + appointment
// ---------------------------------------------------------------------------

it('upserts the contact, creates the appointment, and stores both ids', function () {
    fakeGhlPush();
    $salon = ghlBookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    mapProvider($salon, $stylist);

    // Sync queue driver: the after-commit job runs inline right here.
    $booking = makeBooking($salon, salonOwnerOf($salon), $stylist, serviceFor($salon, $stylist, 60));
    $booking->refresh();

    expect($booking->ghl_appointment_id)->toBe('ghl_a1');
    expect($booking->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_SYNCED);
    expect($booking->last_synced_at)->not->toBeNull();
    expect($booking->client->ghl_contact_id)->toBe('ghl_c1');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/contacts/upsert')
        && $request->hasHeader('Version', GhlClient::CONTACTS_VERSION)
        && $request['locationId'] === 'loc_1'
        && $request['name'] === 'Casey Client');

    // 10:00 local on Mon 2026-06-22 in America/New_York = EDT (-04:00).
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/calendars/events/appointments')
        && $request->hasHeader('Version', GhlClient::CALENDARS_VERSION)
        && $request['calendarId'] === 'cal_master'
        && $request['assignedUserId'] === 'prov_1'
        && $request['contactId'] === 'ghl_c1'
        && $request['ignoreFreeSlotValidation'] === true
        && $request['startTime'] === '2026-06-22T10:00:00-04:00'
        && $request['endTime'] === '2026-06-22T11:00:00-04:00'
        && $request['appointmentStatus'] === 'confirmed');
});

it('reuses a stored contact id without upserting again', function () {
    Http::fake([
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'ghl_a2']),
    ]);
    $salon = ghlBookingSalon();
    $stylist = stylistOf($salon);
    mapProvider($salon, $stylist);

    $booking = pushableBooking($salon, $stylist, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));
    $booking->client->update(['ghl_contact_id' => 'ghl_c9']);

    app(GhlBookingPusher::class)->push($booking);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/contacts/upsert'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/calendars/events/appointments')
        && $request['contactId'] === 'ghl_c9');
    expect($booking->fresh()->ghl_appointment_id)->toBe('ghl_a2');
});

// ---------------------------------------------------------------------------
// Reschedule + cancel
// ---------------------------------------------------------------------------

it('updates the same appointment on re-push instead of creating a duplicate', function () {
    Http::fake([
        'services.leadconnectorhq.com/calendars/events/appointments/*' => Http::response(['id' => 'ghl_a1']),
    ]);
    $salon = ghlBookingSalon();
    $stylist = stylistOf($salon);
    mapProvider($salon, $stylist);

    $booking = pushableBooking($salon, $stylist, CarbonImmutable::parse('2026-06-23 14:00', $salon->timezone), [
        'ghl_appointment_id' => 'ghl_a1',
    ]);
    $booking->client->update(['ghl_contact_id' => 'ghl_c1']);

    app(GhlBookingPusher::class)->push($booking);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/calendars/events/appointments/ghl_a1')
        && $request['startTime'] === '2026-06-23T14:00:00-04:00');
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    expect($booking->fresh()->ghl_appointment_id)->toBe('ghl_a1');
});

it('cancels the GHL appointment when the booking is cancelled', function () {
    Http::fake([
        'services.leadconnectorhq.com/calendars/events/appointments/*' => Http::response(['id' => 'ghl_a1']),
    ]);
    $salon = ghlBookingSalon();
    $stylist = stylistOf($salon);
    mapProvider($salon, $stylist);

    $booking = pushableBooking($salon, $stylist, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone), [
        'status' => BookingStatus::Cancelled,
        'ghl_appointment_id' => 'ghl_a1',
    ]);

    app(GhlBookingPusher::class)->push($booking);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/calendars/events/appointments/ghl_a1')
        && $request['appointmentStatus'] === 'cancelled');
    expect($booking->fresh()->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_SYNCED);
});

it('does nothing remote when a never-pushed booking is cancelled', function () {
    $salon = ghlBookingSalon();
    $stylist = stylistOf($salon);
    mapProvider($salon, $stylist);

    $booking = pushableBooking($salon, $stylist, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone), [
        'status' => BookingStatus::Cancelled,
    ]);

    app(GhlBookingPusher::class)->push($booking);

    Http::assertNothingSent();
    expect($booking->fresh()->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_SKIPPED);
});

// ---------------------------------------------------------------------------
// Skips: booking always succeeds
// ---------------------------------------------------------------------------

it('books successfully with no push when the salon is not connected', function () {
    $salon = bookingSalon(); // no GHL connection at all
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    // Sync driver runs the job inline; preventStrayRequests proves no HTTP.
    $booking = makeBooking($salon, salonOwnerOf($salon), $stylist, serviceFor($salon, $stylist, 60));

    expect($booking->fresh()->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_SKIPPED);
    expect($booking->fresh()->status)->toBe(BookingStatus::Booked);
    Http::assertNothingSent();
});

it('books successfully with no push when the stylist is unmapped', function () {
    $salon = ghlBookingSalon(); // connected, but nobody mapped
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    $booking = makeBooking($salon, salonOwnerOf($salon), $stylist, serviceFor($salon, $stylist, 60));

    expect($booking->fresh()->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_SKIPPED);
    expect($booking->fresh()->ghl_appointment_id)->toBeNull();
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Timezone / DST
// ---------------------------------------------------------------------------

it('sends wall-clock times with DST-correct offsets', function () {
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::sequence()
            ->push(['id' => 'ghl_a_summer'])->push(['id' => 'ghl_a_winter']),
    ]);
    $salon = ghlBookingSalon(); // America/New_York
    $stylist = stylistOf($salon);
    mapProvider($salon, $stylist);

    $summer = pushableBooking($salon, $stylist, CarbonImmutable::parse('2026-07-15 10:00', 'America/New_York'));
    $winter = pushableBooking($salon, $stylist, CarbonImmutable::parse('2026-12-15 10:00', 'America/New_York'));

    app(GhlBookingPusher::class)->push($summer);
    app(GhlBookingPusher::class)->push($winter);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'appointments')
        && $request['startTime'] === '2026-07-15T10:00:00-04:00'); // EDT
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'appointments')
        && $request['startTime'] === '2026-12-15T10:00:00-05:00'); // EST
});

// ---------------------------------------------------------------------------
// Failure handling
// ---------------------------------------------------------------------------

it('retries through a 429 and still syncs', function () {
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::sequence()
            ->push(['message' => 'rate limited'], 429)
            ->push(['contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'ghl_a1']),
    ]);
    $salon = ghlBookingSalon();
    $stylist = stylistOf($salon);
    mapProvider($salon, $stylist);

    $booking = pushableBooking($salon, $stylist, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));

    app(GhlBookingPusher::class)->push($booking);

    expect($booking->fresh()->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_SYNCED);
    Http::assertSentCount(3); // 429 + retried upsert + appointment
});

it('records a visible sync error after exhausted retries, booking intact', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(['message' => 'no'], 401)]);
    $salon = ghlBookingSalon();
    $stylist = stylistOf($salon);
    mapProvider($salon, $stylist);

    $booking = pushableBooking($salon, $stylist, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));

    $job = new SyncBookingToGhl($booking->id);

    try {
        $job->handle(app(GhlBookingPusher::class));
        $this->fail('Expected GhlApiException');
    } catch (GhlApiException $exception) {
        $job->failed($exception); // what the queue does after the final attempt
    }

    $booking->refresh();
    expect($booking->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_FAILED);
    expect($booking->ghl_sync_error)->toContain('GoHighLevel');
    expect($booking->ghl_sync_error)->not->toContain('pit-secret');
    expect($booking->status)->toBe(BookingStatus::Booked); // untouched
    expect($booking->ghl_appointment_id)->toBeNull();
});

<?php

use App\Actions\Bookings\CreateBooking;
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
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
| Phase 6b (one-booking-per-stylist shape): each booking is ONE stylist's
| visit and mirrors to exactly ONE GHL appointment — that booking's own
| times, that booking's own services in the title, on that stylist's mapped
| provider. The booking is the source of truth and always succeeds; the
| mirror runs after commit via the queue (sync driver in tests, so
| dispatches run inline unless faked). All GHL HTTP is faked.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')); // Mon 08:00 EDT
});
afterEach(fn () => Carbon::setTestNow());

/** A booking-ready salon with a complete GHL connection. */
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

/** A factory-built single-stylist booking (skips the create action), no items yet. */
function bareBooking(Salon $salon, array $attrs = []): Booking
{
    $client = Client::factory()->for($salon)->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);

    return Booking::factory()->for($salon)->for($client)->create(array_merge([
        'status' => BookingStatus::Booked,
    ], $attrs));
}

function addItem(Booking $booking, User $stylist, CarbonImmutable $start, int $minutes = 45, string $service = 'Cut & Style'): BookingItem
{
    return BookingItem::factory()->create([
        'salon_id' => $booking->salon_id,
        'booking_id' => $booking->id,
        'service_id' => Service::factory()->for($booking->salon)->create(['name' => $service])->id,
        'stylist_id' => $stylist->id,
        'starts_at' => $start,
        'ends_at' => $start->addMinutes($minutes),
    ]);
}

function fakeContactAndAppointments(array $appointmentIds): void
{
    $sequence = Http::sequence();
    foreach ($appointmentIds as $id) {
        $sequence->push(['id' => $id]);
    }

    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => $sequence,
    ]);
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

it('queues one sync job per stylist booking when a visit is composed across stylists', function () {
    Queue::fake();
    $salon = ghlBookingSalon();
    $owner = salonOwnerOf($salon);
    $anna = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $ben = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [
            ['service_id' => serviceFor($salon, $anna, 45)->id, 'stylist_id' => $anna->id, 'start' => '2026-06-22 11:30'],
            ['service_id' => serviceFor($salon, $ben, 45)->id, 'stylist_id' => $ben->id, 'start' => '2026-06-22 12:15'],
        ],
        'start' => '2026-06-22 11:30',
    ]));

    expect($salon->bookings()->count())->toBe(2);
    Queue::assertPushed(SyncBookingToGhl::class, 2);
});

// ---------------------------------------------------------------------------
// The 1:1 mirror — correct per-booking time and title
// ---------------------------------------------------------------------------

it('pushes each stylist booking at ITS OWN time with ITS OWN service in the title', function () {
    fakeContactAndAppointments(['ghl_a1', 'ghl_a2']);
    $salon = ghlBookingSalon(); // America/New_York
    $owner = salonOwnerOf($salon);
    $anna = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $ben = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    mapProvider($salon, $anna, 'prov_anna');
    mapProvider($salon, $ben, 'prov_ben');
    $dying = Service::factory()->for($salon)->create(['name' => 'Dying', 'duration_min' => 45]);
    $nails = Service::factory()->for($salon)->create(['name' => 'Nails', 'duration_min' => 45]);
    $dying->stylists()->attach($anna->id, ['salon_id' => $salon->id]);
    $nails->stylists()->attach($ben->id, ['salon_id' => $salon->id]);

    // One composed visit: Anna dyes 11:30–12:15, Ben does nails 12:15–13:00.
    // Sync queue: both bookings push inline right here.
    app(CreateBooking::class)->handle($owner, $salon, [
        'client' => ['name' => 'Casey Client'],
        'items' => [
            ['service_id' => $dying->id, 'stylist_id' => $anna->id, 'start' => '2026-06-22 11:30'],
            ['service_id' => $nails->id, 'stylist_id' => $ben->id, 'start' => '2026-06-22 12:15'],
        ],
        'start' => '2026-06-22 11:30',
        'is_walkin' => false,
        'notes' => null,
    ]);

    // Anna's appointment: HER time, HER service only.
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments')
        && $r['assignedUserId'] === 'prov_anna'
        && $r['startTime'] === '2026-06-22T11:30:00-04:00'
        && $r['endTime'] === '2026-06-22T12:15:00-04:00'
        && $r['title'] === 'Casey Client — Dying');

    // Ben's appointment: HIS time, HIS service only — no cross-contamination.
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments')
        && $r['assignedUserId'] === 'prov_ben'
        && $r['startTime'] === '2026-06-22T12:15:00-04:00'
        && $r['endTime'] === '2026-06-22T13:00:00-04:00'
        && $r['title'] === 'Casey Client — Nails');

    Http::assertNotSent(fn ($r): bool => str_contains($r->url(), 'appointments')
        && is_string($r['title'] ?? null) && str_contains($r['title'], 'Dying') && str_contains($r['title'], 'Nails'));

    // Each booking carries its own single appointment id.
    $ids = $salon->bookings()->orderBy('id')->pluck('ghl_appointment_id');
    expect($ids->all())->toBe(['ghl_a1', 'ghl_a2']);

    // The contact was upserted once and shared.
    Http::assertSentCount(3);
});

it('keeps one spanning appointment for two services by the same stylist', function () {
    fakeContactAndAppointments(['ghl_a1']);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    mapProvider($salon, $anna, 'prov_anna');

    $booking = bareBooking($salon);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone), 60, 'Color');
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 11:00', $salon->timezone), 30, 'Blow-dry');

    app(GhlBookingPusher::class)->push($booking);

    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments')
        && $r['startTime'] === '2026-06-23T10:00:00-04:00'
        && $r['endTime'] === '2026-06-23T11:30:00-04:00'
        && str_contains($r['title'], 'Color')
        && str_contains($r['title'], 'Blow-dry'));
    Http::assertSentCount(2); // one upsert + ONE appointment
    expect($booking->fresh()->ghl_appointment_id)->toBe('ghl_a1');
});

// ---------------------------------------------------------------------------
// Reschedule / cancel — one appointment each
// ---------------------------------------------------------------------------

it('updates the same appointment on re-push and skips when nothing changed', function () {
    fakeContactAndAppointments(['ghl_a1', 'updated']);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    mapProvider($salon, $anna, 'prov_anna');

    $booking = bareBooking($salon);
    $item = addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));

    $pusher = app(GhlBookingPusher::class);
    $pusher->push($booking);           // upsert + create = 2 requests
    $pusher->push($booking->fresh());  // unchanged — hash short-circuits, no request

    Http::assertSentCount(2);

    // Reschedule: same appointment updated, never duplicated.
    $item->update(['starts_at' => $item->starts_at->addHour(), 'ends_at' => $item->ends_at->addHour()]);
    $pusher->push($booking->fresh());

    Http::assertSentCount(3);
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/events/appointments/ghl_a1')
        && $r['startTime'] === '2026-06-23T11:00:00-04:00');
    expect($booking->fresh()->ghl_appointment_id)->toBe('ghl_a1');
});

it('cancels only that booking\'s GHL appointment', function () {
    fakeContactAndAppointments(['ghl_a1', 'ghl_a2', 'cancel-ok']);
    $salon = ghlBookingSalon();
    $owner = salonOwnerOf($salon);
    $anna = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $ben = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    mapProvider($salon, $anna, 'prov_anna');
    mapProvider($salon, $ben, 'prov_ben');

    app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [
            ['service_id' => serviceFor($salon, $anna, 45)->id, 'stylist_id' => $anna->id, 'start' => '2026-06-22 11:30'],
            ['service_id' => serviceFor($salon, $ben, 45)->id, 'stylist_id' => $ben->id, 'start' => '2026-06-22 12:15'],
        ],
        'start' => '2026-06-22 11:30',
    ]));

    $annaBooking = $salon->bookings()->whereHas('items', fn ($q) => $q->where('stylist_id', $anna->id))->firstOrFail();

    // Cancel just Anna's booking: only ghl_a1 is cancelled in GHL.
    app(TransitionBookingStatus::class)->handle($owner, $salon, $annaBooking, BookingStatus::Cancelled);

    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/events/appointments/ghl_a1')
        && $r['appointmentStatus'] === 'cancelled');
    Http::assertNotSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/events/appointments/ghl_a2'));
});

it('does nothing remote when a never-pushed booking is cancelled', function () {
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    mapProvider($salon, $anna);

    $booking = bareBooking($salon, ['status' => BookingStatus::Cancelled]);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));

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
// Contact reuse + DST
// ---------------------------------------------------------------------------

it('reuses a stored contact id without upserting again', function () {
    Http::fake([
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'ghl_a2']),
    ]);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    mapProvider($salon, $anna);

    $booking = bareBooking($salon);
    $booking->client->update(['ghl_contact_id' => 'ghl_c9']);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));

    app(GhlBookingPusher::class)->push($booking);

    Http::assertNotSent(fn ($r): bool => str_contains($r->url(), '/contacts/upsert'));
    Http::assertSent(fn ($r): bool => str_contains($r->url(), '/calendars/events/appointments')
        && $r['contactId'] === 'ghl_c9');
});

it('sends wall-clock times with DST-correct offsets', function () {
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::sequence()
            ->push(['id' => 'ghl_a_summer'])->push(['id' => 'ghl_a_winter']),
    ]);
    $salon = ghlBookingSalon(); // America/New_York
    $anna = stylistOf($salon);
    mapProvider($salon, $anna);

    $summer = bareBooking($salon);
    addItem($summer, $anna, CarbonImmutable::parse('2026-07-15 10:00', 'America/New_York'), 60);
    $winter = bareBooking($salon);
    addItem($winter, $anna, CarbonImmutable::parse('2026-12-15 10:00', 'America/New_York'), 60);

    $pusher = app(GhlBookingPusher::class);
    $pusher->push($summer);
    $pusher->push($winter);

    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'appointments')
        && $r['startTime'] === '2026-07-15T10:00:00-04:00'); // EDT
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'appointments')
        && $r['startTime'] === '2026-12-15T10:00:00-05:00'); // EST
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
    $anna = stylistOf($salon);
    mapProvider($salon, $anna);

    $booking = bareBooking($salon);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));

    app(GhlBookingPusher::class)->push($booking);

    expect($booking->fresh()->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_SYNCED);
    Http::assertSentCount(3); // 429 + retried upsert + appointment
});

it('records a visible sync error after exhausted retries, booking intact', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(['message' => 'no'], 401)]);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    mapProvider($salon, $anna);

    $booking = bareBooking($salon);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));

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

// ---------------------------------------------------------------------------
// Split migration
// ---------------------------------------------------------------------------

it('splits a legacy multi-stylist booking into per-stylist bookings with their slice ids', function () {
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    $ben = stylistOf($salon);
    $owner = salonOwnerOf($salon);

    // Build the LEGACY shape: roll back through the per-stylist split
    // migration (step 2 also unwinds the data-only per-service split that
    // sits after it), restoring the slice table + dropping the 1:1 columns…
    $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();

    $client = DB::table('clients')->insertGetId([
        'salon_id' => $salon->id, 'name' => 'Legacy Client', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $bookingId = DB::table('bookings')->insertGetId([
        'salon_id' => $salon->id, 'client_id' => $client, 'status' => 'booked',
        'booked_by_type' => 'salon_owner', 'booked_by_user_id' => $owner->id,
        'source' => 'in_app', 'is_walkin' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $serviceA = DB::table('services')->insertGetId(['salon_id' => $salon->id, 'name' => 'Dying', 'duration_min' => 45, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $serviceB = DB::table('services')->insertGetId(['salon_id' => $salon->id, 'name' => 'Nails', 'duration_min' => 45, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('booking_items')->insert([
        ['salon_id' => $salon->id, 'booking_id' => $bookingId, 'service_id' => $serviceA, 'stylist_id' => $anna->id, 'starts_at' => '2026-06-22 15:30:00', 'ends_at' => '2026-06-22 16:15:00', 'buffer_min' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['salon_id' => $salon->id, 'booking_id' => $bookingId, 'service_id' => $serviceB, 'stylist_id' => $ben->id, 'starts_at' => '2026-06-22 16:15:00', 'ends_at' => '2026-06-22 17:00:00', 'buffer_min' => 0, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('booking_status_events')->insert([
        'salon_id' => $salon->id, 'booking_id' => $bookingId, 'from_status' => null, 'to_status' => 'booked', 'actor_user_id' => $owner->id, 'created_at' => now(),
    ]);
    DB::table('booking_ghl_appointments')->insert([
        ['salon_id' => $salon->id, 'booking_id' => $bookingId, 'stylist_id' => $anna->id, 'ghl_appointment_id' => 'legacy_a1', 'sync_status' => 'synced', 'created_at' => now(), 'updated_at' => now()],
        ['salon_id' => $salon->id, 'booking_id' => $bookingId, 'stylist_id' => $ben->id, 'ghl_appointment_id' => 'legacy_a2', 'sync_status' => 'synced', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // …then run the split.
    $this->artisan('migrate')->assertSuccessful();

    $bookings = Booking::where('salon_id', $salon->id)->orderBy('id')->get();
    expect($bookings)->toHaveCount(2);

    // Linked as one visit, but separate bookings — one per stylist, each
    // carrying only their items and their own GHL appointment id.
    expect($bookings[0]->visit_group_id)->not->toBeNull();
    expect($bookings[0]->visit_group_id)->toBe($bookings[1]->visit_group_id);
    expect($bookings[0]->items()->pluck('stylist_id')->all())->toBe([$anna->id]);
    expect($bookings[1]->items()->pluck('stylist_id')->all())->toBe([$ben->id]);
    expect($bookings[0]->ghl_appointment_id)->toBe('legacy_a1');
    expect($bookings[1]->ghl_appointment_id)->toBe('legacy_a2');

    // Status history carried onto the split booking too.
    expect($bookings[1]->statusEvents()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// One booking per SERVICE — the corrected split
// ---------------------------------------------------------------------------

it('splits 3 services (2 sharing a stylist) into 3 bookings and 3 single-service appointments', function () {
    fakeContactAndAppointments(['ghl_a1', 'ghl_a2', 'ghl_a3']);
    $salon = ghlBookingSalon();
    $owner = salonOwnerOf($salon);
    $simone = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $maya = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    mapProvider($salon, $simone, 'prov_simone');
    mapProvider($salon, $maya, 'prov_maya');

    $serviceA = Service::factory()->for($salon)->create(['name' => 'Cut', 'duration_min' => 30]);
    $serviceB = Service::factory()->for($salon)->create(['name' => 'Color', 'duration_min' => 60]);
    $serviceC = Service::factory()->for($salon)->create(['name' => 'Nails', 'duration_min' => 45]);
    $serviceA->stylists()->attach($simone->id, ['salon_id' => $salon->id]);
    $serviceB->stylists()->attach($simone->id, ['salon_id' => $salon->id]);
    $serviceC->stylists()->attach($maya->id, ['salon_id' => $salon->id]);

    // A + B with Simone (back-to-back), C with Maya at the same time as A.
    app(CreateBooking::class)->handle($owner, $salon, [
        'client' => ['name' => 'Casey Client'],
        'items' => [
            ['service_id' => $serviceA->id, 'stylist_id' => $simone->id, 'start' => '2026-06-22 10:00'],
            ['service_id' => $serviceB->id, 'stylist_id' => $simone->id, 'start' => '2026-06-22 10:30'],
            ['service_id' => $serviceC->id, 'stylist_id' => $maya->id, 'start' => '2026-06-22 10:00'],
        ],
        'start' => '2026-06-22 10:00',
        'is_walkin' => false,
        'notes' => null,
    ]);

    // 3 bookings — NOT 2 grouped by stylist — one service each, one visit.
    $bookings = $salon->bookings()->orderBy('id')->get();
    expect($bookings)->toHaveCount(3);
    expect($bookings->pluck('visit_group_id')->unique())->toHaveCount(1);
    expect($bookings->pluck('visit_group_id')->first())->not->toBeNull();
    foreach ($bookings as $booking) {
        expect($booking->items()->count())->toBe(1);
        expect($booking->ghl_appointment_id)->not->toBeNull();
    }

    // 3 appointments, each titled with its single service at its own time.
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments')
        && $r['assignedUserId'] === 'prov_simone'
        && $r['title'] === 'Casey Client — Cut'
        && $r['startTime'] === '2026-06-22T10:00:00-04:00'
        && $r['endTime'] === '2026-06-22T10:30:00-04:00');
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments')
        && $r['assignedUserId'] === 'prov_simone'
        && $r['title'] === 'Casey Client — Color'
        && $r['startTime'] === '2026-06-22T10:30:00-04:00'
        && $r['endTime'] === '2026-06-22T11:30:00-04:00');
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments')
        && $r['assignedUserId'] === 'prov_maya'
        && $r['title'] === 'Casey Client — Nails'
        && $r['startTime'] === '2026-06-22T10:00:00-04:00');

    // Never a combined title — no stylist-level grouping anywhere.
    Http::assertNotSent(fn ($r): bool => str_contains($r->url(), 'appointments')
        && is_string($r['title'] ?? null) && str_contains($r['title'], 'Cut') && str_contains($r['title'], 'Color'));

    Http::assertSentCount(4); // one contact upsert + three appointments
});

it('pushes two separate appointments when the same stylist performs two services', function () {
    fakeContactAndAppointments(['ghl_a1', 'ghl_a2']);
    $salon = ghlBookingSalon();
    $owner = salonOwnerOf($salon);
    $simone = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    mapProvider($salon, $simone, 'prov_simone');

    app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [
            ['service_id' => serviceFor($salon, $simone, 30)->id, 'stylist_id' => $simone->id, 'start' => '2026-06-22 10:00'],
            ['service_id' => serviceFor($salon, $simone, 30)->id, 'stylist_id' => $simone->id, 'start' => '2026-06-22 14:00'],
        ],
        'start' => '2026-06-22 10:00',
    ]));

    expect($salon->bookings()->count())->toBe(2);
    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && str_contains($r->url(), 'appointments')
        && $r['startTime'] === '2026-06-22T10:00:00-04:00' && $r['endTime'] === '2026-06-22T10:30:00-04:00');
    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && str_contains($r->url(), 'appointments')
        && $r['startTime'] === '2026-06-22T14:00:00-04:00' && $r['endTime'] === '2026-06-22T14:30:00-04:00');
    Http::assertSentCount(3); // upsert + 2 appointments — no grouping
});

it('backfills grouped multi-item bookings into per-service bookings', function () {
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);

    // A pre-correction booking: two items for one stylist, one appointment.
    $booking = bareBooking($salon, ['ghl_appointment_id' => 'legacy_a1', 'ghl_payload_hash' => 'stale']);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone), 60, 'Color');
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 11:00', $salon->timezone), 30, 'Blow-dry');
    $booking->statusEvents()->create(['salon_id' => $salon->id, 'from_status' => null, 'to_status' => 'booked', 'actor_user_id' => null]);

    $migration = require database_path('migrations/2026_07_09_000006_split_bookings_per_service.php');
    $migration->up();

    $bookings = Booking::where('salon_id', $salon->id)->orderBy('id')->get();
    expect($bookings)->toHaveCount(2);
    expect($bookings[0]->visit_group_id)->not->toBeNull();
    expect($bookings[0]->visit_group_id)->toBe($bookings[1]->visit_group_id);
    expect($bookings[0]->items()->count())->toBe(1);
    expect($bookings[1]->items()->count())->toBe(1);

    // The kept booking retains the appointment id with a cleared hash (its
    // next push shrinks the GHL appointment to this single service); the
    // split-off booking will create its own appointment on first push.
    expect($bookings[0]->ghl_appointment_id)->toBe('legacy_a1');
    expect($bookings[0]->ghl_payload_hash)->toBeNull();
    expect($bookings[1]->ghl_appointment_id)->toBeNull();
    expect($bookings[1]->statusEvents()->count())->toBe(1);
});

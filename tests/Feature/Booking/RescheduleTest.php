<?php

use App\Actions\Bookings\RescheduleBooking;
use App\Enums\BookingStatus;
use App\Models\SalonGhlConnection;
use App\Models\StylistProfile;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Rescheduling from both tabs: real slot-engine availability (the booking's
| own slot ignored), server-side conflict rejection, timeline note, and a
| GHL UPDATE of the stored appointment — never a duplicate. Plus status
| changes now pushing to GHL when the mapped status changes.
| Frozen clock: Mon 2026-06-22 12:00 UTC (08:00 EDT).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

it('offers only real slots in the reschedule picker, including around its own current time', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);

    $booking = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00', 'Move Me');
    makeBooking($salon, $owner, $stylist, $service, '2026-06-22 14:00', 'Blocker');

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.appointments.all', ['salon' => $salon])
        ->call('openReschedule', $booking->id)
        ->assertSet('showReschedule', true)
        ->assertSet('rescheduleDate', '2026-06-22');

    $slots = $component->instance()->rescheduleSlots;

    expect($slots)->toContain('10:00');       // its own slot — ignored as a conflict
    expect($slots)->toContain('10:30');       // overlaps only itself — still offered
    expect($slots)->not->toContain('14:00');  // the other booking genuinely blocks
    expect($slots)->not->toContain('13:30');  // 60 min would overlap the blocker
    expect($slots)->not->toContain('08:00');  // outside working hours
});

it('reschedules from the check-in tab: times move, history notes it, GHL gets an update not a create', function () {
    $salon = bookingSalon();
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_1', 'private_integration_token' => 'pit-secret', 'calendar_id' => 'cal_master',
    ]);
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    StylistProfile::updateOrCreate(['salon_id' => $salon->id, 'user_id' => $stylist->id], ['ghl_user_id' => 'prov_1']);
    $service = serviceFor($salon, $stylist, 60);

    Http::fake(['services.leadconnectorhq.com/contacts/*/tags' => Http::response([]),
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'ghl_a1']),
    ]);
    $booking = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00'); // pushes → ghl_a1
    expect($booking->fresh()->ghl_appointment_id)->toBe('ghl_a1');

    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('openReschedule', $booking->id)
        ->set('rescheduleDate', '2026-06-22')
        ->call('reschedule', '15:00')
        ->assertHasNoErrors()
        ->assertSet('showReschedule', false);

    // Times moved (same stylist + service, stored duration kept).
    $item = $booking->fresh()->items()->first();
    expect($item->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('15:00');
    expect($item->ends_at->setTimezone($salon->timezone)->format('H:i'))->toBe('16:00');

    // History records who moved it and from → to.
    $note = $booking->statusEvents()->latest('id')->first();
    expect($note->note)->toContain('Rescheduled from');
    expect($note->note)->toContain('10:00 AM');
    expect($note->note)->toContain('3:00 PM');
    expect($note->actor_user_id)->toBe($owner->id);

    // GHL: the SAME appointment updated — a PUT to ghl_a1, no second POST.
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/events/appointments/ghl_a1')
        && $r['startTime'] === '2026-06-22T15:00:00-04:00');
    expect($booking->fresh()->ghl_appointment_id)->toBe('ghl_a1');
    Http::assertSentCount(4) /* incl. the one-time client tag add */; // upsert + create + the one update
});

it('rejects a conflicting reschedule server-side with a clear message', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);

    $booking = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00');
    makeBooking($salon, $owner, $stylist, $service, '2026-06-22 14:00', 'Blocker');

    expect(fn () => app(RescheduleBooking::class)->handle($owner, $salon, $booking->fresh(), '2026-06-22 14:00'))
        ->toThrow(ValidationException::class, 'That time was just taken');

    expect($booking->fresh()->items()->first()->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('10:00');
});

it('reschedules cleanly on an unconnected salon — no GHL calls, no error', function () {
    $salon = bookingSalon(); // no connection
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    app(RescheduleBooking::class)->handle($owner, $salon, $booking->fresh(), '2026-06-22 15:00');

    expect($booking->fresh()->items()->first()->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('15:00');
    Http::assertNothingSent();
});

it('lets front desk reschedule but never a stylist', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    // A stylist cannot reschedule — not even their own booking.
    expect(fn () => app(RescheduleBooking::class)->handle($stylist, $salon, $booking->fresh(), '2026-06-22 15:00'))
        ->toThrow(AuthorizationException::class);

    // Front desk can.
    app(RescheduleBooking::class)->handle(frontDeskOf($salon), $salon, $booking->fresh(), '2026-06-22 15:00');
    expect($booking->fresh()->items()->first()->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('15:00');
});

it('pushes a status change to GHL when the mapped status changes (no-show)', function () {
    $salon = bookingSalon();
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_1', 'private_integration_token' => 'pit-secret', 'calendar_id' => 'cal_master',
    ]);
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    StylistProfile::updateOrCreate(['salon_id' => $salon->id, 'user_id' => $stylist->id], ['ghl_user_id' => 'prov_1']);

    Http::fake(['services.leadconnectorhq.com/contacts/*/tags' => Http::response([]),
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'ghl_a1']),
    ]);
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    // From the appointments tab: booked → no-show pushes 'noshow' to GHL.
    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.all', ['salon' => $salon])
        ->call('changeStatus', $booking->id, 'no_show')
        ->assertHasNoErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow);
    expect($booking->statusEvents()->latest('id')->value('to_status'))->toBe(BookingStatus::NoShow);
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/events/appointments/ghl_a1')
        && $r['appointmentStatus'] === 'noshow');
});

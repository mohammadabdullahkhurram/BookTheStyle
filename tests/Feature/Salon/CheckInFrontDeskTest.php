<?php

use App\Actions\Bookings\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

/*
| The Check-in tab as the day-of FRONT-DESK view: today only, client by
| client, a visible visit-state flow (Scheduled → Check in → Check out;
| Cancel / No-show close out, no-show undoable), NO rescheduling (that
| lives on Calendar/Appointments), and a Check out button that is purely a
| payments-coming-soon placeholder — the only real action behind it is
| marking the visit complete. Frozen clock: Mon 2026-06-22 12:00 UTC.
*/

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
    Bus::fake([SyncBookingToGhl::class]);
});
afterEach(fn () => Carbon::setTestNow());

/** A salon with one booking today and one tomorrow. */
function frontDesk(): array
{
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    stylistWithHours($salon, 1, 9 * 60, 17 * 60, $stylist); // Tuesday too
    $service = serviceFor($salon, $stylist, 60);
    $service->update(['name' => 'Signature Cut']);
    $today = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 14:00');
    $today->client->update(['name' => 'Tina Today']);
    $tomorrow = makeBooking($salon, $owner, $stylist, $service, '2026-06-23 10:00');
    $tomorrow->client->update(['name' => 'Tom Tomorrow']);

    return compact('salon', 'owner', 'stylist', 'service', 'today', 'tomorrow');
}

it('shows only TODAY, client by client with services — and offers no reschedule', function () {
    ['salon' => $salon, 'owner' => $owner] = frontDesk();

    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->assertSee('Tina Today')
        ->assertSee('Signature Cut')
        ->assertSee('2:00 PM')
        ->assertDontSee('Tom Tomorrow')          // tomorrow belongs to Calendar/Appointments
        ->assertDontSee(__('Reschedule'))         // no future-scheduling here
        ->assertSee(__('Check in'));

    // The component has no reschedule surface at all any more.
    expect(method_exists(Livewire::new('pages::salon.appointments.index'), 'openReschedule'))->toBeFalse();
});

it('walks the visit-state flow at CLIENT level: Scheduled → Checked in → Checked out; no-show closes out and UNDOES', function () {
    ['salon' => $salon, 'owner' => $owner, 'today' => $booking] = frontDesk();
    $clientId = $booking->client_id;

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon]);

    // Check in — the client arrived.
    $component->call('checkInClient', $clientId)->assertHasNoErrors();
    expect($booking->refresh()->status)->toBe(BookingStatus::Arrived);
    $component->assertSee(__('Checked in'))->assertSee(__('Check out'));

    // Check out — via the placeholder modal's one real action.
    $component->call('openCheckout', $clientId)
        ->assertSet('showCheckout', true)
        ->assertSee(__('In-app payments are coming soon'))
        ->call('completeVisit')
        ->assertHasNoErrors()
        ->assertSet('showCheckout', false);
    expect($booking->refresh()->status)->toBe(BookingStatus::Completed);

    // No-show closes out — and is undoable back to an active booking.
    ['salon' => $salon2, 'owner' => $owner2, 'today' => $booking2] = frontDesk();
    $c2 = Livewire::actingAs($owner2)->test('pages::salon.appointments.index', ['salon' => $salon2]);
    $c2->call('markNoShowClient', $booking2->client_id)->assertHasNoErrors();
    expect($booking2->refresh()->status)->toBe(BookingStatus::NoShow);
    $c2->assertSee(__('Undo no-show'));
    $c2->call('undoNoShowClient', $booking2->client_id)->assertHasNoErrors();
    expect($booking2->refresh()->status)->toBe(BookingStatus::Booked);
    $c2->assertSee(__('Check in')); // active again, full flow restored
});

it('groups a multi-booking client into EXACTLY ONE block listing every service line', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'today' => $booking] = frontDesk();

    // The same client booked a SECOND separate visit today (the Abdullah
    // case: an 8:15 facial and an 8:30 cut as two bookings).
    $facial = serviceFor($salon, $stylist, 30);
    $facial->update(['name' => 'Radiance Facial']);
    $second = makeBooking($salon, $owner, $stylist, $facial, '2026-06-22 16:00');
    $second->update(['client_id' => $booking->client_id]);
    $second->items()->update(['booking_id' => $second->id]);

    $html = Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('$refresh')
        ->html();

    // ONE block: the client's name renders exactly once…
    expect(substr_count($html, 'Tina Today'))->toBe(1);
    // …with BOTH services as lines inside it, and both start times.
    expect($html)->toContain('Signature Cut')->toContain('Radiance Facial');
    expect($html)->toContain('2:00 PM')->toContain('4:00 PM');
    // ONE History link — never the numbered per-appointment variants.
    expect(substr_count($html, '>'.__('History').'<'))->toBe(1);
    expect($html)->not->toContain('History 1')->not->toContain('History 2');

    // Checking the client in checks in BOTH visits at once.
    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('checkInClient', $booking->client_id)
        ->assertHasNoErrors();
    expect($booking->refresh()->status)->toBe(BookingStatus::Arrived);
    expect($second->refresh()->status)->toBe(BookingStatus::Arrived);
});

it('opens ONE combined History per client — every visit\'s events, chronologically, visit-labelled', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'today' => $booking] = frontDesk();

    // A second separate visit for the same client, then a transition on
    // EACH visit so both histories carry events.
    $facial = serviceFor($salon, $stylist, 30);
    $facial->update(['name' => 'Radiance Facial']);
    $second = makeBooking($salon, $owner, $stylist, $facial, '2026-06-22 16:00');
    $second->update(['client_id' => $booking->client_id]);

    $transition = app(TransitionBookingStatus::class);
    $transition->handle($owner, $salon, $booking->refresh(), BookingStatus::Arrived);
    $transition->handle($owner, $salon, $second->refresh(), BookingStatus::NoShow);

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('openClientTimeline', $booking->client_id)
        ->assertSet('showTimeline', true);

    $timeline = $component->instance()->timeline;
    // Both visits' events are in the ONE stream, oldest first…
    expect($timeline->pluck('to_status')->all())->toContain(BookingStatus::Arrived, BookingStatus::NoShow);
    expect($timeline->pluck('created_at')->map->getTimestamp()->all())
        ->toBe($timeline->pluck('created_at')->map->getTimestamp()->sort()->values()->all());
    // …each labelled with its visit's start so the merge stays readable.
    expect($timeline->pluck('visit_label')->unique()->filter()->all())->toContain('2:00 PM', '4:00 PM');
});

it('renders a single-appointment client as one block with one service line and the Check out button', function () {
    ['salon' => $salon, 'owner' => $owner] = frontDesk();

    $html = Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->html();

    expect(substr_count($html, 'Tina Today'))->toBe(1);
    expect(substr_count($html, 'Signature Cut'))->toBe(1);
    expect($html)->toContain(__('Check out')); // present on every block
});

it('keeps the Check out button a pure coming-soon placeholder — nothing money-related exists', function () {
    ['salon' => $salon, 'owner' => $owner, 'today' => $booking] = frontDesk();

    // Even before check-in the button exists on the block; the popup is
    // informational only (no complete action offered).
    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('openCheckout', $booking->client_id)
        ->assertSee(__('In-app payments are coming soon'))
        ->assertDontSee(__('Mark visit complete'));
    expect($booking->refresh()->status)->toBe(BookingStatus::Booked);

    // Checked in: the popup offers the one real action, opening changes nothing.
    app(TransitionBookingStatus::class)->handle($owner, $salon, $booking, BookingStatus::Arrived);
    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('openCheckout', $booking->client_id)
        ->assertSee(__('In-app payments are coming soon'))
        ->assertSee(__('Mark visit complete'));
    expect($booking->refresh()->status)->toBe(BookingStatus::Arrived);
});

it('auto-flags an elapsed unarrived booking as no-show — undoable, never charging anything', function () {
    ['salon' => $salon, 'owner' => $owner, 'today' => $booking] = frontDesk();
    $salon->forceFill(['auto_no_show' => true, 'auto_no_show_grace_minutes' => 15])->save();

    // The visit (2–3 PM) elapses plus grace; the sweep flags it.
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 19:30:00', 'UTC')); // 3:30 PM New York
    $this->artisan('bookings:close-elapsed')->assertExitCode(0);
    expect($booking->refresh()->status)->toBe(BookingStatus::NoShow);

    // A FLAG, not a verdict: the salon undoes it from the front-desk view.
    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('undoNoShowClient', $booking->client_id)
        ->assertHasNoErrors();
    expect($booking->refresh()->status)->toBe(BookingStatus::Booked);
});

it('leaves the Appointments list untouched: reschedule still lives there with the full toolset', function () {
    ['salon' => $salon, 'owner' => $owner] = frontDesk();

    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.all', ['salon' => $salon])
        ->assertSee(__('Reschedule'))
        ->assertSee('Tina Today')
        ->assertSee('Tom Tomorrow'); // all dates, unlike check-in
});

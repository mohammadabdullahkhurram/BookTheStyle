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

it('walks the visit-state flow: Scheduled → Checked in → Checked out; no-show closes out and UNDOES', function () {
    ['salon' => $salon, 'owner' => $owner, 'today' => $booking] = frontDesk();

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon]);

    // Check in.
    $component->call('changeStatus', $booking->id, 'arrived')->assertHasNoErrors();
    expect($booking->refresh()->status)->toBe(BookingStatus::Arrived);
    $component->assertSee(__('Checked in'))->assertSee(__('Check out'));

    // Check out — via the placeholder modal's one real action.
    $component->call('openCheckout', $booking->id)
        ->assertSet('showCheckout', true)
        ->assertSee(__('In-app payments are coming soon'))
        ->call('completeVisit')
        ->assertHasNoErrors()
        ->assertSet('showCheckout', false);
    expect($booking->refresh()->status)->toBe(BookingStatus::Completed);

    // No-show closes out — and is undoable back to an active booking.
    ['salon' => $salon2, 'owner' => $owner2, 'today' => $booking2] = frontDesk();
    $c2 = Livewire::actingAs($owner2)->test('pages::salon.appointments.index', ['salon' => $salon2]);
    $c2->call('changeStatus', $booking2->id, 'no_show')->assertHasNoErrors();
    expect($booking2->refresh()->status)->toBe(BookingStatus::NoShow);
    $c2->assertSee(__('Undo no-show'));
    $c2->call('changeStatus', $booking2->id, 'booked')->assertHasNoErrors();
    expect($booking2->refresh()->status)->toBe(BookingStatus::Booked);
    $c2->assertSee(__('Check in')); // active again, full flow restored
});

it('keeps the Check out button a pure coming-soon placeholder — nothing money-related exists', function () {
    ['salon' => $salon, 'owner' => $owner, 'today' => $booking] = frontDesk();
    app(TransitionBookingStatus::class)->handle($owner, $salon, $booking, BookingStatus::Arrived);

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('openCheckout', $booking->id)
        ->assertSee(__('In-app payments are coming soon'))
        ->assertSee(__('Mark visit complete'));

    // Opening the popup changes NOTHING — no state moved, no charge path.
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
        ->call('changeStatus', $booking->id, 'booked')
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

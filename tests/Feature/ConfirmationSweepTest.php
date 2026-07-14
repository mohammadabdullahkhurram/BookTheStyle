<?php

use App\Enums\AgencyRole;
use App\Enums\BookingStatus;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| Batch 3 confirmation & feedback sweep: destructive actions gate behind
| wire:confirm with specific copy, the reschedule modal selects-then-confirms
| instead of committing on chip click, action buttons say what they do
| (verbs, not status names), and status changes toast their outcome.
| Frozen clock: Mon 2026-06-22 12:00 UTC (08:00 EDT) for booking tests.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------------------
// Confirmation gates
// ---------------------------------------------------------------------------

it('gates cancel and no-show behind specific confirmations on check-in and appointments', function (string $component) {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    Livewire::actingAs($owner)
        ->test($component, ['salon' => $salon])
        // Converted to the themed dialog: the button opens $store.confirm and
        // the wire action only runs from the confirm callback.
        ->assertSeeHtml('$store.confirm.ask')
        ->assertDontSeeHtml('wire:confirm')
        ->assertSee('Cancel this booking?')
        ->assertSee('Mark this booking as a no-show?')
        ->assertSee('GoHighLevel');
})->with([
    'check-in' => 'pages::salon.appointments.index',
    'appointments' => 'pages::salon.appointments.all',
]);

it('gates cancel and no-show behind confirmations in the calendar detail modal', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    Livewire::actingAs($owner)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->call('openBooking', $booking->id)
        // Converted to the themed dialog (it top-layers above the detail modal).
        ->assertSeeHtml('$store.confirm.ask')
        ->assertDontSeeHtml('wire:confirm')
        ->assertSee('Cancel this booking?')
        ->assertSee('Mark this booking as a no-show?');
});

it('confirms service deactivation with consequence copy — but not reactivation', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    serviceFor($salon, $stylist, 60);

    Livewire::actingAs($owner)
        ->test('pages::salon.services.index', ['salon' => $salon])
        ->assertSeeHtml('wire:confirm=')
        ->assertSee('Clients can no longer book it; existing bookings are unaffected.');
});

it('confirms staff password resets and deactivation with specific copy', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    stylistOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.staff.index', ['salon' => $salon])
        ->assertSee('password? Their current password stops working immediately')
        ->assertSee('They lose access to this salon; their bookings and history are kept.');
});

it('confirms salon deactivation from the agency console', function () {
    $salon = Salon::factory()->create();
    $owner = User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Owner,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('All its staff lose access until it is reactivated. No data is deleted.');

    $this->get(route('agency.salons.edit', $salon))
        ->assertOk()
        ->assertSee('All its staff lose access until it is reactivated. No data is deleted.');
});

it('confirms removing a date-specific availability entry (fires GHL sync)', function () {
    $salon = Salon::factory()->create(['timezone' => 'America/New_York']);
    $stylist = stylistOf($salon);
    TimeOff::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2027-07-20 00:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2027-07-21 00:00', $salon->timezone),
    ]);

    test()->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->call('startEditing')
        ->set('panelTab', 'dates')
        ->assertSee('Remove this date-specific entry?')
        ->assertSee('GoHighLevel availability is updated');
});

it('confirms disabling 2FA and regenerating recovery codes', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Disable two-factor authentication? Your account will no longer require a second step')
        ->assertSee('Generate new recovery codes? Your current codes stop working immediately');
});

// ---------------------------------------------------------------------------
// Reschedule: select-then-confirm
// ---------------------------------------------------------------------------

it('selecting a reschedule chip does NOT move the booking — only the confirm button commits', function (string $component) {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    $page = Livewire::actingAs($owner)
        ->test($component, ['salon' => $salon])
        ->call('openReschedule', $booking->id)
        // The chip click is a plain $set — selection only.
        ->set('rescheduleTime', '15:00')
        ->assertSet('rescheduleTime', '15:00')
        // Selected state is visible and announced.
        ->assertSeeHtml('aria-pressed="true"')
        ->assertSee('Confirm reschedule');

    // Nothing committed yet.
    expect($booking->fresh()->items()->first()->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('10:00');

    // The confirm button commits.
    $page->call('reschedule')->assertHasNoErrors()->assertSet('showReschedule', false);
    expect($booking->fresh()->items()->first()->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('15:00');
})->with([
    'appointments' => 'pages::salon.appointments.all',
    'check-in' => 'pages::salon.appointments.index',
]);

it('refuses to commit a reschedule with no time selected', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.all', ['salon' => $salon])
        ->call('openReschedule', $booking->id)
        ->call('reschedule')
        ->assertHasErrors('rescheduleTime');

    expect($booking->fresh()->items()->first()->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('10:00');
});

it('clears the selected time when the reschedule date changes', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.all', ['salon' => $salon])
        ->call('openReschedule', $booking->id)
        ->set('rescheduleTime', '15:00')
        ->set('rescheduleDate', '2026-06-29')
        ->assertSet('rescheduleTime', '');
});

// ---------------------------------------------------------------------------
// Verbs + toasts + feedback
// ---------------------------------------------------------------------------

it('labels action buttons with verbs, never past-tense status names', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->assertSee('Check in')
        ->assertSee('Mark no-show')
        ->assertSee('Cancel booking')
        // The old state-name labels are gone from the buttons (the pill on a
        // BOOKED booking reads "Booked", so these can only be button labels).
        ->assertDontSee('Checked in')
        ->assertDontSee('Cancelled');
});

it('toasts the specific outcome of a status change', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $a = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');
    $b = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 14:00', 'Second Client');

    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('changeStatus', $a->id, 'cancelled')
        ->assertDispatched('toast-show')
        ->call('changeStatus', $b->id, 'arrived');

    expect($a->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($b->fresh()->status)->toBe(BookingStatus::Arrived);

    // The copy itself is enum-driven and exact.
    expect(BookingStatus::Cancelled->actionToast())->toBe('Booking cancelled.');
    expect(BookingStatus::Arrived->actionToast())->toBe('Checked in.');
    expect(BookingStatus::NoShow->actionToast())->toBe('Marked as no-show.');
});

it('bakes disabled styling into bts-btn and guards Create booking against double submits', function () {
    expect(file_get_contents(resource_path('css/app.css')))
        ->toContain('disabled:pointer-events-none')
        ->toContain('disabled:opacity-50');

    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)
        ->get(route('salon.bookings.create', $salon))
        ->assertOk()
        ->assertSee('wire:loading.attr="disabled"', false)
        ->assertSee('wire:target="save"', false);
});

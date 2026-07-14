<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| Themed confirmation modal (x-ui.confirm-modal): the single in-app dialog that
| replaces native browser confirm() / wire:confirm. Covers the component's
| dialog semantics, its Alpine store contract (act ONLY on confirm), the layout
| mount, and the first converted call sites (calendar feed + appointments).
| Frozen clock matches ConfirmationSweepTest for the booking-page renders.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------------------
// The component itself
// ---------------------------------------------------------------------------

it('renders an accessible dialog: role, aria wiring, focus trap, Esc + scrim cancel', function () {
    $html = Blade::render('<x-ui.confirm-modal />');

    expect($html)
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('aria-labelledby="bts-confirm-title"')
        ->toContain('aria-describedby="bts-confirm-message"')
        ->toContain('id="bts-confirm-title"')
        ->toContain('id="bts-confirm-message"')
        // Focus moves in while open and restores on close.
        ->toContain('x-trap.noscroll="$store.confirm.show"')
        // Esc and the scrim both cancel.
        ->toContain('@keydown.escape.window')
        ->toContain('bts-scrim');
});

it('confirms and cancels through the store, and marks danger by more than colour', function () {
    $html = Blade::render('<x-ui.confirm-modal />');

    expect($html)
        // Cancel closes without acting; confirm runs the captured action.
        ->toContain('$store.confirm.cancel()')
        ->toContain('$store.confirm.proceed()')
        ->toContain('Cancel</button>')
        // Danger uses the solid danger button; normal severity the accent one.
        ->toContain('bts-btn-danger-solid')
        ->toContain('bts-btn-primary')
        // Non-colour danger signals: distinct icon per severity + verb label.
        ->toContain('$store.confirm.danger')
        ->toContain('x-text="$store.confirm.confirmLabel"');
});

it('backs the dialog with the Alpine confirm store that acts only on confirm', function () {
    $js = file_get_contents(resource_path('js/app.js'));

    expect($js)
        ->toContain("Alpine.store('confirm'")
        // ask() captures the action; cancel() drops it; only proceed() runs it.
        ->toContain('ask(options, onConfirm)')
        ->toContain('this.onConfirm = null')
        ->toContain('if (run) run()');
});

it('is mounted once, globally, in the app shell', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('bts-confirm-title');
});

// ---------------------------------------------------------------------------
// Converted call sites (proof pass — see x-ui.confirm-modal for the recipe)
// ---------------------------------------------------------------------------

it('calendar feed regenerate + revoke use the themed dialog with the original copy', function () {
    $user = User::factory()->create();

    // Connected state: "Regenerate link" + "Revoke" buttons.
    $page = Livewire::actingAs($user)
        ->test('pages::settings.calendar-feed')
        ->call('generate')
        ->assertSeeHtml('$store.confirm.ask')
        ->assertDontSeeHtml('wire:confirm')
        // The exact wire:confirm copy, preserved as the dialog message.
        ->assertSee('Regenerate the link? Your existing calendar subscription will stop updating until you re-add the new link.');

    Livewire::actingAs($user)
        ->test('pages::settings.calendar-feed')
        ->assertSee('Regenerate the link? The old link stops working immediately.')
        ->assertSee('Revoke your calendar link? It will stop updating any calendar it was added to.')
        // Revoke is danger; regenerate is a normal-severity confirm.
        ->assertSeeHtml('danger: true')
        ->assertSeeHtml('danger: false')
        ->assertDontSeeHtml('wire:confirm');
});

it('appointment cancel / no-show buttons act only through the confirm callback', function (string $component) {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    Livewire::actingAs($owner)
        ->test($component, ['salon' => $salon])
        // The destructive action only runs from inside the confirm callback.
        ->assertSeeHtml('$store.confirm.ask')
        ->assertSeeHtml('$wire.changeStatus(')
        ->assertDontSeeHtml('wire:confirm')
        // Copy comes verbatim from BookingStatus::confirmMessage().
        ->assertSee('Cancel this booking?')
        ->assertSee('Mark this booking as a no-show?');
})->with([
    'check-in' => 'pages::salon.appointments.index',
    'appointments' => 'pages::salon.appointments.all',
]);

it('compiles the confirm payload — no raw Blade or @js survives to the browser', function (string $component) {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    $html = Livewire::actingAs($owner)
        ->test($component, ['salon' => $salon])
        ->html();

    // Inside <x-…> component-tag attributes Blade never compiles @js and
    // chokes on double quotes — these leak literally if a call site regresses.
    expect($html)->not->toContain('@js(')
        ->and($html)->not->toContain('Js::from');
})->with([
    'check-in' => 'pages::salon.appointments.index',
    'appointments' => 'pages::salon.appointments.all',
]);

// ---------------------------------------------------------------------------
// Tenant isolation (unchanged by the conversion)
// ---------------------------------------------------------------------------

it('still denies the appointments screen to members of other salons', function () {
    $salon = bookingSalon();
    $outsider = salonOwnerOf(bookingSalon());

    Livewire::actingAs($outsider)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->assertForbidden();
});

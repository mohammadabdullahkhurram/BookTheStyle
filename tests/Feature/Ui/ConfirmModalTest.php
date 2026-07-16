<?php

use App\Enums\AgencyRole;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Finder\SplFileInfo;

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

it('renders an accessible native dialog: showModal top layer, labelling, Esc + backdrop cancel', function () {
    $html = Blade::render('<x-ui.confirm-modal />');

    expect($html)
        // A native <dialog> via showModal(): implicit role=dialog + aria-modal,
        // and it joins the top layer ABOVE any open flux:modal (calendar detail).
        ->toContain('<dialog')
        ->toContain('$el.showModal()')
        ->toContain('aria-labelledby="bts-confirm-title"')
        ->toContain('aria-describedby="bts-confirm-message"')
        ->toContain('id="bts-confirm-title"')
        ->toContain('id="bts-confirm-message"')
        // Body scroll lock while open; native dialog restores focus on close.
        ->toContain('x-trap.noscroll="$store.confirm.show"')
        // Native close (Esc) syncs the store; clicking the ::backdrop cancels.
        ->toContain('@close="$store.confirm.show && $store.confirm.cancel()"')
        ->toContain('$event.target === $el && $store.confirm.cancel()')
        ->toContain('bts-confirm-dialog');

    // The ::backdrop is the themed scrim (a top-layer dialog has no scrim div).
    expect(file_get_contents(resource_path('css/app.css')))
        ->toContain('.bts-confirm-dialog::backdrop');
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
        ->assertSee('Regenerate the link? The old link stops working immediately and the connection status starts over — your calendar keeps working only once you add the new link.');

    Livewire::actingAs($user)
        ->test('pages::settings.calendar-feed')
        ->assertSee('Regenerate the link? The old link stops working immediately and the connection status starts over — your calendar keeps working only once you add the new link.')
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
// Converted call sites (batch A)
// ---------------------------------------------------------------------------

it('salon deactivation on the dashboard and agency edit uses the themed dialog', function () {
    $salon = Salon::factory()->create();
    $owner = User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Owner,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('$store.confirm.ask', false)
        ->assertDontSee('wire:confirm', false)
        ->assertSee('All its staff lose access until it is reactivated. No data is deleted.');

    $this->get(route('agency.salons.edit', $salon))
        ->assertOk()
        ->assertSee('$store.confirm.ask', false)
        ->assertDontSee('wire:confirm', false)
        ->assertSee('All its staff lose access until it is reactivated. No data is deleted.');
});

it('calendar detail cancel / no-show buttons use the themed dialog', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    Livewire::actingAs($owner)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->call('openBooking', $booking->id)
        ->assertSeeHtml('$store.confirm.ask')
        ->assertDontSeeHtml('wire:confirm')
        ->assertSee('Cancel this booking?')
        ->assertSee('Mark this booking as a no-show?');
});

it('salon settings logo removal, webhook rotation and token regeneration use the themed dialog', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/test/logo.png', 'png-bytes');
    $salon = Salon::factory()->create(['branding' => ['logo_path' => 'branding/test/logo.png']]);
    $owner = salonOwnerOf($salon);
    SalonGhlConnection::factory()->for($salon)->create(['webhook_secret' => str_repeat('ab', 24)]);

    $page = Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('generateApiToken')
        ->assertSeeHtml('$store.confirm.ask')
        ->assertSee('Remove the logo? The widget shows the salon name alone until a new one is uploaded.')
        ->assertSee('Rotate the webhook secret? The current one stops working immediately.')
        ->assertSee('Regenerate the API token? The current token stops working immediately.');

    // Every confirm on the page is themed — including the GHL disconnect card.
    expect(substr_count($page->html(), 'wire:confirm'))->toBe(0);
    $page->assertSee('Disconnect GoHighLevel? The stored token will be deleted. Stylist mappings are kept.');
});

it('widget deletion uses the themed dialog', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $salon->defaultWidget();

    Livewire::actingAs($owner)
        ->test('pages::salon.widgets', ['salon' => $salon])
        // A second widget makes the "Delete this widget" action available.
        ->call('createWidget')
        ->assertSeeHtml('$store.confirm.ask')
        ->assertDontSeeHtml('wire:confirm')
        ->assertSee('Delete this widget? Sites embedding it stop showing a booking form. Existing bookings are kept.');
});

// ---------------------------------------------------------------------------
// Repo-wide guard: no native confirms can sneak back in
// ---------------------------------------------------------------------------

it('leaves no native confirm in any view — every confirmation is the themed dialog', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file) => str_ends_with($file->getFilename(), '.blade.php'))
        ->filter(function (SplFileInfo $file) {
            // Blade comments may MENTION wire:confirm (conversion notes, the
            // component recipe) — only live template code counts.
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));

            return str_contains($source, 'wire:confirm')
                || preg_match('/(?<![.\w$])confirm\(/', $source) === 1;
        })
        ->map(fn (SplFileInfo $file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('onboarding token regeneration uses the themed dialog once a token exists', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.onboarding', ['salon' => $salon])
        ->call('goTo', 'api_token')
        ->call('generateApiToken')
        ->assertDontSeeHtml('wire:confirm')
        ->assertSeeHtml('$store.confirm.ask')
        ->assertSee('Regenerate the API token? The current one stops working immediately — the GHL custom actions must be updated.');
});

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

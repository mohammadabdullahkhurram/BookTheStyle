<?php

use App\Enums\BookingStatus;
use App\Models\Salon;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
| Batch 4 accessibility & contrast: AA-passing tokens, a shared focus ring +
| skip link + labeled landmarks, keyboard-reachable 2FA recovery path,
| aria-pressed/aria-current state, real labels on previously placeholder-only
| inputs, scope="col" table headers, labeled calendar slots, and a whitelisted
| settings hash that can never render a blank page.
*/

// ---------------------------------------------------------------------------
// Tokens, focus ring, skip link
// ---------------------------------------------------------------------------

it('ships AA-passing text tokens, a shared focus-visible ring, and reserves fainter for decoration', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('--color-faint: #746c62')          // 5.17:1 on card, 4.71:1 on paper (warm umber)
        ->not->toContain('--color-faint: #9c9890')     // the 2.87:1 original
        // Warm-boutique accent set: plum (6.5:1 with white text) + AA ink.
        ->toContain('--accent: #824c71')
        ->toContain('--accent-ink: #6b3358')
        ->toContain('--color-blush-ink: #9c4f3f')      // 5.83:1 on card, 5.03:1 on its tint
        ->toContain(':focus-visible')
        ->toContain('outline: 2px solid var(--accent)');

    // fainter must not be used as a text colour anywhere (decoration only).
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php'))
        ->filter(fn ($f) => preg_match('/text-fainter(?!\/)/', $f->getContents()))
        ->map(fn ($f) => $f->getRelativePathname())
        ->values()->all();
    expect($offenders)->toBe([]);
});

it('renders a skip link and labeled landmarks in the app layout', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)->get(route('salon.show', $salon))
        ->assertOk()
        ->assertSee('Skip to content')
        ->assertSee('href="#main-content"', false)
        ->assertSee('id="main-content"', false)
        ->assertSee('aria-label="Primary"', false);
});

// ---------------------------------------------------------------------------
// 2FA challenge: keyboard-reachable recovery path + visible code errors
// ---------------------------------------------------------------------------

it('exposes the 2FA recovery-code toggle as a real button and renders code errors', function () {
    $view = file_get_contents(resource_path('views/pages/auth/two-factor-challenge.blade.php'));

    // The method toggle is a <button> (focusable), not a click-only <span>.
    expect($view)
        ->toContain('log in using a recovery code')
        ->toContain("@error('code')")
        ->not->toContain('<span x-show="!showRecoveryInput" @click="toggleInput()"');

    preg_match_all('/<button[^>]*@click="toggleInput\(\)"/s', $view, $m);
    expect($m[0])->toHaveCount(2);
});

// ---------------------------------------------------------------------------
// State + name semantics
// ---------------------------------------------------------------------------

it('marks the calendar view segments and stylist chips with aria-pressed and labels empty slots', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $anna = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $anna->update(['name' => 'Anna Andersson']);
    stylistWithHours($salon, 0, 9 * 60, 17 * 60); // second stylist → filter chips render

    Livewire::actingAs($owner)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->assertSeeHtml('aria-pressed')
        ->assertSeeHtml('aria-label="Book Anna Andersson at 9:00 AM"');
    Carbon::setTestNow();
});

it('marks the booking form slot chips with aria-pressed', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)->get(route('salon.bookings.create', $salon))->assertOk();

    $view = file_get_contents(resource_path('views/pages/salon/bookings/create.blade.php'));
    expect($view)->toContain('aria-pressed="{{ $item[\'time\'] === $slot ? \'true\' : \'false\' }}"');
});

it('labels the clients search and the duration/buffer override inputs', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    stylistOf($salon)->update(['name' => 'Maya Malik']);

    $this->actingAs($owner)->get(route('salon.clients', $salon))
        ->assertOk()
        ->assertSee('Search by name, phone, or email');

    $this->get(route('salon.services', $salon))
        ->assertOk()
        ->assertSee('Time (min)')
        ->assertSee('aria-label="Maya Malik — duration in minutes (blank = service default)"', false);
});

it('adds scope=col to every data-table header', function () {
    $offenders = collect(File::allFiles(resource_path('views/pages')))
        ->filter(fn ($f) => preg_match('/<th (?!scope="col")/', $f->getContents()))
        ->map(fn ($f) => $f->getRelativePathname())
        ->values()->all();

    expect($offenders)->toBe([]);
});

it('marks the active account-settings tab with aria-current', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('aria-current="page"', false);
});

// ---------------------------------------------------------------------------
// Settings hash whitelist — no blank page on a bad or unauthorized fragment
// ---------------------------------------------------------------------------

it('whitelists the settings tab hash so unknown fragments fall back to general', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $response = $this->actingAs($owner)->get(route('salon.settings', $salon))->assertOk();

    // The Alpine init resolves the hash against the visible-tab whitelist.
    $response->assertSee('resolve(hash)', false);
    $response->assertSee("this.tabs.includes(hash) ? hash : 'general'", false);
    $response->assertSee('@hashchange.window', false);
    $response->assertSee('aria-current', false);
});

// ---------------------------------------------------------------------------
// Status pill fallbacks
// ---------------------------------------------------------------------------

it('gives unknown statuses a labeled neutral pill and the cancelled pill AA text', function () {
    $html = Blade::render('<x-ui.status-pill :status="$status" />', ['status' => 'imported_legacy']);
    expect($html)->toContain('Imported legacy')->toContain('#56534C');

    $html = Blade::render('<x-ui.status-pill :status="$status" />', ['status' => BookingStatus::Cancelled]);
    expect($html)->toContain('Cancelled')->toContain('#6B6862')->not->toContain('#9C9890');
});

<?php

use App\Models\Salon;
use App\Support\NoirTheme;

/*
| The noir deep-glass dark language, proving on a small route list before
| app-wide rollout. Proof routes render data-theme="noir" on <body> (body-
| scoped so wire:navigate swaps carry it); everything else stays light. The
| token layer carries AA-verified light-on-dark values and glass is chrome/
| overlay-only.
*/

it('renders the noir theme on exactly the proof routes', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    // Proof screens are dark.
    $this->actingAs($owner)->get(route('salon.show', $salon))
        ->assertOk()->assertSee('data-theme="noir"', false);
    $this->get(route('salon.appointments.all', $salon))
        ->assertOk()->assertSee('data-theme="noir"', false);

    // Everything else stays light.
    $this->get(route('salon.clients', $salon))
        ->assertOk()->assertDontSee('data-theme="noir"', false);
    $this->get(route('salon.services', $salon))
        ->assertOk()->assertDontSee('data-theme="noir"', false);
});

it('renders the login showcase dark with the glass panel', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-theme="noir"', false)
        ->assertSee('bts-glass-panel', false);

    // Route list is the single source of truth.
    expect(NoirTheme::ROUTES)->toContain('login', 'salon.show', 'salon.appointments.all');
});

it('ships the noir token layer: AA dark ramp, glass spec, dark focus ring', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        // Deep backdrop — never pure black; content on near-opaque surfaces.
        ->toContain("body[data-theme='noir']")
        ->toContain('--color-paper: #141019')
        ->toContain('--color-card: #201a26')
        // Warm off-white ink (14.6:1 on card), AA ramp below it.
        ->toContain('--color-ink: #f2ede8')
        ->toContain('--color-faint: #a39a91')
        // Light-plum accent ink (8.4:1) + dark-visible focus ring.
        ->toContain('--accent-ink: #d9a9c6')
        // Glass is tokenised: translucent bg + blur + light border + edge.
        ->toContain('--glass-bg: rgb(255 255 255 / 0.06)')
        ->toContain('--glass-border: rgb(255 255 255 / 0.12)')
        ->toContain('backdrop-filter: blur(var(--glass-blur))')
        // Glass chrome + overlays only.
        ->toContain('.bts-chrome')
        ->toContain("body[data-theme='noir'] dialog");
});

it('keeps the glass chrome markers on the layout chrome without breaking the responsive contract', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $response = $this->actingAs($owner)->get(route('salon.show', $salon))->assertOk();

    // Sidebar, mobile top bar, and nav drawer are chrome (glass in noir)…
    expect(substr_count($response->getContent(), 'bts-chrome'))->toBeGreaterThanOrEqual(3);

    // …and the Batch 2 responsive classes are intact.
    $response->assertSee('hidden h-svh shrink-0 flex-col border-e border-border bg-card transition-[width] duration-200 lg:flex', false);
    $response->assertSee('aria-label="Open navigation"', false);
});

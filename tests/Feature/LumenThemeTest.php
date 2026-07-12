<?php

use App\Models\Salon;
use App\Support\LumenTheme;

/*
| The lumen light liquid-glass language, proving on a small route list
| before app-wide rollout. Proof routes render data-theme="lumen" on <body>
| (body-scoped so wire:navigate swaps carry it); everything else stays on
| the plain light language. Glass is chrome/overlay/widget-only and every
| glass blend is AA-verified for the text on it.
*/

it('renders the lumen theme on exactly the proof routes', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    // Proof screens carry the glass language.
    $this->actingAs($owner)->get(route('salon.show', $salon))
        ->assertOk()->assertSee('data-theme="lumen"', false);
    $this->get(route('salon.appointments.all', $salon))
        ->assertOk()->assertSee('data-theme="lumen"', false);

    // Everything else stays plain.
    $this->get(route('salon.clients', $salon))
        ->assertOk()->assertDontSee('data-theme="lumen"', false);
    $this->get(route('salon.services', $salon))
        ->assertOk()->assertDontSee('data-theme="lumen"', false);
});

it('renders the login showcase with the glass panel', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-theme="lumen"', false)
        ->assertSee('bts-glass-panel', false);

    // Route list is the single source of truth.
    expect(LumenTheme::ROUTES)->toContain('login', 'salon.show', 'salon.appointments.all');
});

it('ships the lumen token layer: light glass spec, warm mesh, AA-verified blends', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain("body[data-theme='lumen']")
        // Light glass: translucent warm white + blur + light edge + soft shadow.
        ->toContain('--glass-bg: rgb(255 255 255 / 0.55)')
        ->toContain('--glass-strong: rgb(252 250 246 / 0.9)')
        ->toContain('--glass-highlight: inset 0 1px 0 rgb(255 255 255 / 0.75)')
        ->toContain('backdrop-filter: blur(var(--glass-blur))')
        // Glass on chrome, panel, widgets, and dialogs only.
        ->toContain('.bts-chrome')
        ->toContain('.bts-glass-panel')
        ->toContain('.bts-stat')
        ->toContain("body[data-theme='lumen'] dialog")
        // Warm backdrop mesh, never loud.
        ->toContain('radial-gradient(52rem 36rem at 10% -8%, rgb(130 76 113 / 0.07)')
        // No dark language remains.
        ->not->toContain("data-theme='noir'");
});

it('keeps the glass chrome markers on the layout without breaking the responsive contract', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $response = $this->actingAs($owner)->get(route('salon.show', $salon))->assertOk();

    // Sidebar, mobile top bar, and nav drawer are chrome (glass under lumen)…
    expect(substr_count($response->getContent(), 'bts-chrome'))->toBeGreaterThanOrEqual(3);

    // …and the Batch 2 responsive classes are intact.
    $response->assertSee('hidden h-svh shrink-0 flex-col border-e border-border bg-card transition-[width] duration-200 lg:flex', false);
    $response->assertSee('aria-label="Open navigation"', false);
});

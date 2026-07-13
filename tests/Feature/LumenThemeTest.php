<?php

use App\Models\Salon;
use App\Support\LumenTheme;

/*
| The lumen light liquid-glass language survives as part of the CLASSIC
| theme: a Classic salon's proof routes render exactly as they did before
| the Marble rollout. Marble salons (the default) and the login page render
| Marble instead.
*/

it('renders lumen on the proof routes for CLASSIC salons only', function () {
    $classic = Salon::factory()->create(['app_theme' => 'classic']);
    $owner = salonOwnerOf($classic);

    // Classic proof screens keep the glass language — the pre-Marble look.
    $this->actingAs($owner)->get(route('salon.show', $classic))
        ->assertOk()->assertSee('data-theme="lumen"', false);
    $this->get(route('salon.appointments.all', $classic))
        ->assertOk()->assertSee('data-theme="lumen"', false);

    // Classic non-proof screens stay plain (the base token set).
    $this->get(route('salon.clients', $classic))
        ->assertOk()->assertDontSee('data-theme=', false);

    // A Marble salon (the default) renders Marble on the same routes.
    $marble = Salon::factory()->create();
    $marbleOwner = salonOwnerOf($marble);
    $this->actingAs($marbleOwner)->get(route('salon.show', $marble))
        ->assertOk()->assertSee('data-theme="marble"', false)
        ->assertDontSee('data-theme="lumen"', false);

    expect(LumenTheme::ROUTES)->toBe(['salon.show', 'salon.appointments.all']);
});

it('renders the auth screens under the BRAND (landing) palette, not the salon theme', function () {
    // Every auth screen shares the one auth layout, so the front door — the
    // login, password reset, invite accept, 2FA challenge — matches the
    // public landing palette rather than any salon app theme.
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-theme="brand"', false)
        ->assertDontSee('data-theme="marble"', false)
        ->assertSee('bts-glass-panel', false); // the landing-card panel

    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('data-theme="brand"', false);
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

it('keeps the chrome markers on the layout without breaking the responsive contract', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $response = $this->actingAs($owner)->get(route('salon.show', $salon))->assertOk();

    // Sidebar, mobile top bar, and nav drawer are chrome (theme-restyled)…
    expect(substr_count($response->getContent(), 'bts-chrome'))->toBeGreaterThanOrEqual(3);

    // …and the Batch 2 responsive classes are intact under Marble.
    $response->assertSee('hidden h-svh shrink-0 flex-col border-e border-border bg-card transition-[width] duration-200 lg:flex', false);
    $response->assertSee('aria-label="Open navigation"', false);
});

<?php

use App\Models\Salon;
use App\Support\WidgetBranding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Settings → Branding: logo upload + colour scheme + widget font, persisted
| on salons.branding (additive JSON, backfill-safe defaults) and consumed by
| the public booking widget per slug. Owner/admin only, tenant-scoped.
*/

it('persists the two brand controls — accent and logo — and drives app + widgets', function () {
    Storage::fake('public');
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    // The Branding surface is exactly TWO controls now: the colour-wheel +
    // hex accent (no presets) and the logo. Widget colours/fonts live per
    // widget in the Widgets area.
    $component = Livewire::actingAs($owner)->test('pages::salon.settings', ['salon' => $salon]);
    $component->assertSee('type="color"', false)
        ->assertDontSee('Accent preset')
        ->assertDontSee('Secondary color')
        ->assertDontSee('Background color')
        ->assertDontSee('Widget font')
        ->assertDontSee('Widgets ↗');

    $component->set('accent', '#2F5D7C')
        ->set('logo', UploadedFile::fake()->image('logo.png', 320, 120))
        ->call('saveBranding')
        ->assertHasNoErrors();

    $branding = $salon->fresh()->branding;
    expect($branding['accent'])->toBe('#2F5D7C');
    expect($branding['logo_path'])->toStartWith('branding/'.$salon->id.'/');
    Storage::disk('public')->assertExists($branding['logo_path']);

    // The accent recolours the ACTIVE theme (Marble default) via the brand
    // slot, with derived readable on-accent text…
    $this->actingAs($owner)->get(route('salon.show', $salon->fresh()))
        ->assertOk()
        ->assertSee('data-theme="marble"', false)
        ->assertSee('--brand-accent: #2F5D7C', false)
        ->assertSee('--brand-accent-foreground: #FFFFFF', false);

    // …and under Classic too (theme = style; accent = brand).
    $salon->fresh()->update(['app_theme' => 'classic']);
    $this->actingAs($owner)->get(route('salon.show', $salon->fresh()))
        ->assertOk()
        ->assertSee('--brand-accent: #2F5D7C', false);

    // A PALE accent derives DARK on-accent text.
    Livewire::actingAs($owner)->test('pages::salon.settings', ['salon' => $salon->fresh()])
        ->set('accent', '#F2D8A0')->call('saveBranding')->assertHasNoErrors();
    $this->actingAs($owner)->get(route('salon.show', $salon->fresh()))
        ->assertSee('--brand-accent-foreground: #1C1B1A', false);

    // The resolver hands widgets the salon accent by default…
    $theme = WidgetBranding::for($salon->fresh());
    expect($theme['accent']['accent'])->toBe('#F2D8A0');
    expect($theme['logo_url'])->toContain($branding['logo_path']);

    // …and a specific widget can override it.
    $widget = $salon->fresh()->defaultWidget();
    $widget->update(['branding' => ['accent' => '#824C71']]);
    expect(WidgetBranding::for($salon->fresh(), null, $widget->fresh())['accent']['accent'])->toBe('#824C71');
});

it('rejects invalid branding input: bad hexes and non-image uploads', function () {
    Storage::fake('public');
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('accent', 'not-a-color')
        ->call('saveBranding')
        ->assertHasErrors('accent');

    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('logo', UploadedFile::fake()->create('malware.pdf', 200, 'application/pdf'))
        ->call('saveBranding')
        ->assertHasErrors('logo');

    expect($salon->fresh()->branding)->toBeNull();
});

it('replaces the old logo file on re-upload and deletes it on removal', function () {
    Storage::fake('public');
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('logo', UploadedFile::fake()->image('one.png'))
        ->call('saveBranding')->assertHasNoErrors();
    $first = $salon->fresh()->branding['logo_path'];

    $component->set('logo', UploadedFile::fake()->image('two.png'))
        ->call('saveBranding')->assertHasNoErrors();
    $second = $salon->fresh()->branding['logo_path'];

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    $component->call('removeLogo')->assertHasNoErrors();
    expect($salon->fresh()->branding['logo_path'] ?? null)->toBeNull();
    Storage::disk('public')->assertMissing($second);
});

it('backfills sensible defaults for salons with no branding at all', function () {
    $salon = Salon::factory()->create(['branding' => null]);

    $theme = WidgetBranding::for($salon);
    expect($theme['secondary'])->toBe(WidgetBranding::DEFAULT_SECONDARY);
    expect($theme['surface'])->toBe(WidgetBranding::DEFAULT_SURFACE);
    expect($theme['font']['key'])->toBe(WidgetBranding::DEFAULT_FONT);
    expect($theme['logo_url'])->toBeNull();
    expect($theme['accent']['accent'])->toBe('#824C71'); // the app's plum
});

it('warns only for brand pairings the derived foreground cannot rescue', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $component = Livewire::actingAs($owner)->test('pages::salon.settings', ['salon' => $salon]);

    // Mid grey: NEITHER white nor dark text reaches 4.5:1 on it.
    $component->set('accent', '#7F7F7F');
    expect($component->instance()->brandingContrastWarning)->not->toBeNull();

    // Pale pink is FINE now — dark on-accent text is derived automatically.
    $component->set('accent', '#E8C9DD');
    expect($component->instance()->brandingContrastWarning)->toBeNull();

    // Any strong accent clears the warning — on-accent text is derived.
    $component->set('accent', '#824C71');
    expect($component->instance()->brandingContrastWarning)->toBeNull();
});

it('keeps branding tenant-scoped: staff cannot save it, and the gallery tab is gone', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    // Settings itself is manage-gated (mount aborts for staff).
    Livewire::actingAs($stylist)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->assertForbidden();

    // The temporary widget gallery is fully removed.
    expect(Route::has('salon.widgetdesigns'))->toBeFalse();

    $owner = salonOwnerOf($salon);
    $this->actingAs($owner)->get(route('salon.show', $salon))
        ->assertOk()
        ->assertDontSee('aria-label="Widget designs"', false);
});

it('recolors THEMED widget pages with the salon accent via the brand slot (the marble regression)', function () {
    $salon = Salon::factory()->create(['branding' => ['accent' => '#5C7458']]);
    $widget = $salon->defaultWidget();
    expect($widget->themeKey())->toBe('marble'); // a themed body re-declares --accent

    // Themed bodies read --accent from var(--brand-accent, <theme default>),
    // so the page MUST fill the --brand-accent* slot: a bare :root --accent
    // is overridden by body[data-theme] and the salon accent was silently
    // dropped on every themed widget (they all rendered marble coral).
    $html = $this->get('http://'.$salon->slug.'.'.config('app.domain').'/widget/'.$widget->public_id)
        ->assertOk()
        ->getContent();

    expect($html)->toContain('--brand-accent: #5C7458');
    expect($html)->toContain('--accent: #5C7458'); // classic (theme-less) widgets read this one
    expect($html)->toContain('data-theme="marble"');
});

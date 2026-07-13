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

it('persists the full branding set: colours, font, and an uploaded logo', function () {
    Storage::fake('public');
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('accent', '#2F5D7C')
        ->set('brandSecondary', '#C2A15A')
        ->set('brandSurface', '#F2EFE9')
        ->set('brandFont', 'modern')
        ->set('logo', UploadedFile::fake()->image('logo.png', 320, 120))
        ->call('saveBranding')
        ->assertHasNoErrors();

    $branding = $salon->fresh()->branding;
    expect($branding['accent'])->toBe('#2F5D7C');
    expect($branding['secondary'])->toBe('#C2A15A');
    expect($branding['surface'])->toBe('#F2EFE9');
    expect($branding['font'])->toBe('modern');
    expect($branding['logo_path'])->toStartWith('branding/'.$salon->id.'/');
    Storage::disk('public')->assertExists($branding['logo_path']);

    // The resolver hands the widget exactly this theme.
    $theme = WidgetBranding::for($salon->fresh());
    expect($theme['accent']['accent'])->toBe('#2F5D7C');
    expect($theme['font']['key'])->toBe('modern');
    expect($theme['logo_url'])->toContain($branding['logo_path']);
});

it('rejects invalid branding input: bad hexes, unknown fonts, non-image uploads', function () {
    Storage::fake('public');
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('brandSecondary', 'not-a-color')
        ->call('saveBranding')
        ->assertHasErrors('brandSecondary');

    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('brandFont', 'comic-sans')
        ->call('saveBranding')
        ->assertHasErrors('brandFont');

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

it('warns when the accent is too light for white button text', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $component = Livewire::actingAs($owner)->test('pages::salon.settings', ['salon' => $salon]);

    $component->set('accent', '#E8C9DD'); // pale pink — white-on-it fails AA
    expect($component->instance()->brandingContrastWarning)->not->toBeNull();

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

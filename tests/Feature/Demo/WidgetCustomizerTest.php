<?php

use App\Models\Salon;
use Livewire\Livewire;

/*
| The two-pane live widget customizer — demo-gated behind the PROMOTE SWITCH
| (widgets page customizerEnabled()). Demo salons get the customizer with a
| live postMessage preview; real salons keep the classic page untouched.
| What Pest can assert is the wiring (markup, gate, guards, iframe target,
| bridge script); the actual live re-theming is client-side JS and is
| eyeball-verified on the demo host.
*/

function demoSalonFor($test): Salon
{
    $test->get('http://app.'.config('app.domain').'/demo')->assertRedirect();

    return Salon::query()
        ->whereKey(session('demo_salon_id'))
        ->where('is_demo', true)
        ->firstOrFail();
}

it('renders the two-pane customizer for a demo salon, wired to the in-app preview', function () {
    $salon = demoSalonFor($this);
    $host = 'http://demo.'.config('app.domain');

    $html = $this->get($host.'/widgets')
        ->assertOk()
        ->assertSee('data-customizer', false)
        // The parent side of the bridge: draft messages, origin-scoped.
        ->assertSee('bts-widget-draft', false)
        ->assertSee('window.location.origin', false)
        // The preview iframe targets the IN-APP route on the demo host…
        ->assertSee($host.'/widgets/preview/'.$salon->defaultWidget()->public_id, false)
        ->getContent();

    // …and never the public widget host ({slug}. is unroutable + refuses demos).
    expect($html)->not->toContain('http://'.$salon->slug.'.');

    // Both embed snippet variants survive the redesign.
    expect($html)->toContain('data-bookthestyle-salon');
    expect($html)->toContain(__('Recommended: script embed (auto-sizes to content)'));
});

it('arms the live-draft listener on the preview page only', function () {
    $salon = demoSalonFor($this);
    $host = 'http://demo.'.config('app.domain');

    // Preview page: listener present, origin-checked.
    $this->get($host.'/widgets/preview/'.$salon->defaultWidget()->public_id)
        ->assertOk()
        ->assertSee('bts-preview-overrides', false)
        ->assertSee('event.origin !== window.location.origin', false);

    // The PUBLIC widget page never ships the listener. (Forget the demo
    // request's currentSalon binding first — the test process shares one
    // container across requests; real requests never do.)
    app()->forgetInstance('currentSalon');
    $real = Salon::factory()->create(['active' => true]);
    $this->get('http://'.$real->slug.'.'.config('app.domain').'/widget/'.$real->defaultWidget()->public_id)
        ->assertOk()
        ->assertDontSee('bts-preview-overrides', false);
});

it('blocks every widget persist in demo while the draft stays client-side', function () {
    $salon = demoSalonFor($this);
    $widget = $salon->defaultWidget();
    $nameBefore = $widget->name;
    $themeBefore = $widget->theme;
    $countBefore = $salon->widgets()->count();

    Livewire::actingAs(auth()->user())
        ->test('pages::salon.widgets', ['salon' => $salon])
        ->set('name', 'Renamed In Demo')
        ->set('accent', '#123456')
        ->call('save')
        ->call('saveTheme', 'classic')
        ->call('createWidget')
        ->call('deleteWidget', $widget->id)
        ->assertHasNoErrors();

    $widget->refresh();
    expect($widget->name)->toBe($nameBefore);
    expect($widget->theme)->toBe($themeBefore);
    expect($widget->branding['accent'] ?? null)->toBeNull();
    expect($salon->widgets()->count())->toBe($countBefore);
});

it('keeps the classic widgets page and working saves for real salons', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    // The gate: no customizer, classic markup intact.
    $this->actingAs($owner)
        ->get(route('salon.widgets', $salon))
        ->assertOk()
        ->assertDontSee('data-customizer', false)
        ->assertSee('href="#widget-preview"', false);

    // Saves still persist for real salons.
    Livewire::actingAs($owner)
        ->test('pages::salon.widgets', ['salon' => $salon])
        ->set('name', 'Front Window Widget')
        ->set('accent', '#123456')
        ->call('save')
        ->assertHasNoErrors();

    $widget = $salon->defaultWidget()->refresh();
    expect($widget->name)->toBe('Front Window Widget');
    expect($widget->branding['accent'])->toBe('#123456');
});

<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
| Walkthrough fixes: the compact side nav (full nav + pinned user chip fit
| the viewport; the mobile drawer keeps full touch targets), the single
| agency Salons tab with a Gallery/List toggle (Gallery default; the
| Dashboard no longer duplicates the salon listing), and the scissor logo.
*/

it('renders the full side nav with the user chip pinned and the scissor logo', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $response = $this->actingAs($owner)->get(route('salon.show', $salon))->assertOk();

    // The user profile chip is in the layout (pinned at the sidebar bottom).
    $response->assertSee('data-test="sidebar-menu-button"', false)
        // The primary nav scrolls internally on short viewports instead of
        // pushing the chip out of view.
        ->assertSee('min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto', false)
        // The sidebar brand is the COMPACT lockup: scissors mark + the full
        // word as real text — the raster full-logo reads "ROOKTHESTYLE"
        // below ~40px (the scissors erase the B's bowls), so it no longer
        // appears at sidebar size.
        ->assertSee('/images/icon-logo.png', false)
        ->assertSee('BookTheStyle</span>', false)
        ->assertDontSee('/images/full-logo.png')
        // Batch 2 responsive contract intact.
        ->assertSee('aria-label="Open navigation"', false);

    // Compact desktop nav items; the mobile drawer restores 44px targets.
    $css = file_get_contents(resource_path('css/app.css'));
    expect($css)->toContain('.bts-drawer-left .bts-nav-item');
});

it('has ONE Salons surface — the picker — with Gallery default and a List toggle for managers', function () {
    $agency = Agency::factory()->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $active = Salon::factory()->for($agency)->create(['name' => 'Alpha Studio']);
    Salon::factory()->for($agency)->create(['name' => 'Beta Salon', 'active' => false]);

    $component = Livewire::actingAs($owner)->test('pages::dashboard');

    // Gallery is the default: the picker cards with the salon data + actions.
    $component->assertSet('view', 'gallery')
        ->assertSee('Welcome back')
        ->assertSee('data-view="gallery"', false)
        ->assertSee('Alpha Studio')
        ->assertSee('Beta Salon')
        ->assertSee('Inactive')
        ->assertSee('Edit');

    // The toggle switches to the table presentation of the SAME data.
    $component->set('view', 'list')
        ->assertSee('data-view="list"', false)
        ->assertSee('Timezone')
        ->assertSee('Alpha Studio');

    // Actions still work from the page (deactivate confirms + toggles).
    $component->call('toggleActive', $active->id);
    expect($active->fresh()->active)->toBeFalse();

    // The sidebar carries exactly ONE Salons entry (desktop + drawer render
    // the shared nav once each), with the scissor icon, ordered after
    // Dashboard; the retired duplicate route is gone.
    $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
    expect(substr_count($html, 'aria-label="Salons"'))->toBe(2);
    expect(Route::has('agency.salons.index'))->toBeFalse();
    $offset = 0;
    foreach (['aria-label="Dashboard"', 'aria-label="Salons"', 'aria-label="Reporting"', 'aria-label="Users"', 'aria-label="New salon"'] as $needle) {
        $position = strpos($html, $needle, $offset);
        expect($position)->not->toBeFalse();
        $offset = $position + 1;
    }
});

it('keeps the Dashboard as a dashboard — the salon listing is not duplicated there', function () {
    $agency = Agency::factory()->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    Salon::factory()->for($agency)->create(['name' => 'Alpha Studio']);

    $response = $this->actingAs($owner)->get(route('agency.overview'))->assertOk();

    // Stat cards link out; no salons table on the dashboard anymore.
    $response->assertSee(route('dashboard'), false)
        ->assertSee(route('agency.reports'), false)
        ->assertSee(route('agency.users.index'), false);
    expect($response->getContent())->not->toContain('<table');

    // Empty state (with zero salons) still reads on the dashboard.
    $empty = Agency::factory()->create();
    $emptyOwner = User::factory()->create(['agency_id' => $empty->id, 'agency_role' => AgencyRole::Owner]);
    $this->actingAs($emptyOwner)->get(route('agency.overview'))
        ->assertOk()
        ->assertSee('No salons yet');
});

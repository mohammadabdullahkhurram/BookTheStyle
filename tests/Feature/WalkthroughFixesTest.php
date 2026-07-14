<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
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
        // The scissor BookTheStyle lockup is untouched.
        ->assertSee('/images/full-logo.png', false)
        // Batch 2 responsive contract intact.
        ->assertSee('aria-label="Open navigation"', false);

    // Compact desktop nav items; the mobile drawer restores 44px targets.
    $css = file_get_contents(resource_path('css/app.css'));
    expect($css)->toContain('.bts-drawer-left .bts-nav-item');
});

it('has ONE agency Salons tab: Gallery by default, List via the toggle', function () {
    $agency = Agency::factory()->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $active = Salon::factory()->for($agency)->create(['name' => 'Alpha Studio']);
    Salon::factory()->for($agency)->create(['name' => 'Beta Salon', 'active' => false]);

    $component = Livewire::actingAs($owner)->test('pages::agency.salons.index');

    // Gallery is the default: cards with the salon data + actions.
    $component->assertSet('view', 'gallery')
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
});

it('keeps the Dashboard as a dashboard — the salon listing is not duplicated there', function () {
    $agency = Agency::factory()->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    Salon::factory()->for($agency)->create(['name' => 'Alpha Studio']);

    $response = $this->actingAs($owner)->get(route('agency.overview'))->assertOk();

    // Stat cards link out; no salons table on the dashboard anymore.
    $response->assertSee(route('agency.salons.index'), false)
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

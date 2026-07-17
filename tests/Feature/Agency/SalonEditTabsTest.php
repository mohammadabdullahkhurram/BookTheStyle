<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use Livewire\Livewire;

/*
| The agency salon-edit screen reuses the salon-settings tab pattern —
| vertical rail, hash routing with a WHITELISTED fallback (an unknown or
| unauthorized #fragment lands on General, never a blank panel),
| aria-current, hashchange back/forward, stacked mobile rail — grouped as
| General · Booking policy & type · Owner · GoHighLevel. A failed save lands
| on the tab that owns the first error.
*/

function editScreenAgencyOwner(): array
{
    $agency = Agency::factory()->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $salon = Salon::factory()->for($agency)->create();

    return [$salon, $owner];
}

it('renders the settings tab pattern with each group\'s fields in its panel', function () {
    [$salon, $agencyOwner] = editScreenAgencyOwner();

    $html = $this->actingAs($agencyOwner)
        ->get(route('agency.salons.edit', $salon))
        ->assertOk()
        ->getContent();

    // The exact pattern, not a new one: whitelist resolve + hashchange +
    // aria-current + the stacked mobile treatment.
    expect($html)->toContain('resolve(hash)');
    expect($html)->toContain('@hashchange.window');
    expect($html)->toContain('aria-current');
    expect($html)->toContain('max-md:flex-col');
    expect($html)->toContain("tabs: ['general', 'policy', 'owner', 'ghl']");

    // Rail labels.
    foreach ([__('General'), 'Booking policy &amp; type', __('Owner'), __('GoHighLevel')] as $label) {
        expect($html)->toContain($label);
    }

    // Each panel carries its fields: General (business + subdomain +
    // timezone), Policy (walk-ins + salon type), Owner (owner fields +
    // ownership), GHL (connection form).
    foreach ([
        __('Business / trading name'), __('Subdomain'), __('Timezone'),
        __('Allow walk-ins'), __('Salon type'),
        __('Owner name'), __('The owner is also a stylist'), __('Ownership'),
    ] as $field) {
        expect($html)->toContain($field);
    }
});

it('whitelists the hash so an invalid fragment falls back to General — the resolver is authoritative', function () {
    [$salon, $agencyOwner] = editScreenAgencyOwner();

    // The fallback is the Alpine resolver; assert its shape is the settings
    // one (default general, includes() check) — the same guard settings has.
    $html = $this->actingAs($agencyOwner)
        ->get(route('agency.salons.edit', $salon))
        ->assertOk()
        ->getContent();

    expect($html)->toContain("return this.tabs.includes(hash) ? hash : 'general'");
    expect($html)->toContain('this.tab = this.resolve(window.location.hash.slice(1))');
});

it('lands a failed save on the tab that owns the error, per section', function () {
    [$salon, $agencyOwner] = editScreenAgencyOwner();

    // Owner-tab error: broken owner email.
    Livewire::actingAs($agencyOwner)
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->set('contact_email', 'not-an-email')
        ->call('save')
        ->assertHasErrors('contact_email')
        ->assertDispatched('salon-edit-tab', tab: 'owner');

    // Policy-tab error: out-of-range advance window.
    Livewire::actingAs($agencyOwner)
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->set('max_advance_days', 9999)
        ->call('save')
        ->assertHasErrors('max_advance_days')
        ->assertDispatched('salon-edit-tab', tab: 'policy');

    // General-tab error: empty business name.
    Livewire::actingAs($agencyOwner)
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->set('name', '')
        ->call('save')
        ->assertHasErrors('name')
        ->assertDispatched('salon-edit-tab', tab: 'general');
});

it('still saves each section independently through the preserved actions', function () {
    [$salon, $agencyOwner] = editScreenAgencyOwner();
    salonOwnerOf($salon); // an owner exists → reconcile syncs, no provisioning

    // The shared save action (General/Policy/Owner panels submit it).
    Livewire::actingAs($agencyOwner)
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->set('max_advance_days', 45)
        ->call('save')
        ->assertHasNoErrors();
    expect($salon->fresh()->max_advance_days)->toBe(45);

    // The GHL panel's own form + action still works.
    Livewire::actingAs($agencyOwner)
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->set('ghlLocationId', 'loc_tabs123')
        ->call('saveGhlConnection')
        ->assertHasNoErrors();
    expect($salon->fresh()->ghlConnection?->location_id)->toBe('loc_tabs123');
});

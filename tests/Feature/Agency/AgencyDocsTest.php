<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Support\AgencyDocs;

/*
| The agency Documentation tab — NATIVE Blade doc pages (x-docs.* kit),
| grouped hard into SOPs vs Technical, rendered for EVERY agency role and
| nobody else. Salon users and guests are refused server-side; the demo
| host never carries the route.
*/

function docsAgencyUser(AgencyRole $role): User
{
    return User::factory()->create([
        'agency_id' => Agency::factory()->create()->id,
        'agency_role' => $role,
    ]);
}

it('renders the tab for every agency role with SOPs and Technical clearly separated', function () {
    foreach ([AgencyRole::Owner, AgencyRole::Admin, AgencyRole::User] as $role) {
        $this->actingAs(docsAgencyUser($role))
            ->get(route('agency.docs'))
            ->assertOk()
            // The two top-level groups, each with its own labelled card.
            ->assertSeeInOrder(['SOPs', 'no technical background needed', 'Technical', 'for the technical team'])
            ->assertSee('How to set up a new salon — step by step')
            ->assertSee('BookTheStyle × GoHighLevel — technical integration reference');
    }
});

it('blocks salon users server-side; guests bounce; the demo host has no docs route', function () {
    $salon = Salon::factory()->create();

    foreach ([salonOwnerOf($salon), salonAdminOf($salon), stylistOf($salon)] as $actor) {
        $this->actingAs($actor)->get(route('agency.docs'))->assertForbidden();
    }
    $this->actingAs(salonOwnerOf($salon))
        ->get(route('agency.docs', ['doc' => 'technical-integration-reference']))
        ->assertForbidden();

    $this->get('http://demo.'.config('app.domain').'/agency/docs')->assertNotFound();
});

it('bounces unauthenticated guests to login', function () {
    $this->get(route('agency.docs'))->assertRedirect(route('login'));
});

it('renders the SOP as a native page: sections, steps, callouts, screenshot slots, on-page nav', function () {
    $response = $this->actingAs(docsAgencyUser(AgencyRole::User))
        ->get(route('agency.docs', ['doc' => 'salon-setup-sop']))
        ->assertOk();

    // Anchored sections + the on-page navigation chips.
    $response->assertSee('id="part-a-bookthestyle"', false)
        ->assertSee('id="checklist"', false)
        ->assertSee(__('On this page'))
        ->assertSee(__('Go-live checklist'));

    // Native components: numbered steps, a warning callout, screenshot slots.
    $response->assertSee(__('Choose the salon type'))
        ->assertSee(__('Important'))
        ->assertSee(__('Screenshot to add'))
        ->assertSee('Takes bookings');

    // GHL-instance specifics render as visible fill-in slots.
    $response->assertSee(__('Loopflo snapshot name'));
});

it('renders the technical reference with real BTS specifics and diagrams as inline SVG', function () {
    $response = $this->actingAs(docsAgencyUser(AgencyRole::Owner))
        ->get(route('agency.docs', ['doc' => 'technical-integration-reference']))
        ->assertOk();

    // Real BookTheStyle-side values, verified from the codebase.
    $response->assertSee('/api/v1/booking/availability')
        ->assertSee('/api/v1/booking/create')
        ->assertSee('api.booking.availability')
        ->assertSee('btsk_', false)
        ->assertSee('Authorization: Bearer')
        ->assertSee('Voice-AI Booking API')
        ->assertSee('slot_unavailable')
        ->assertSee('X-Webhook-Secret');

    // GHL-instance specifics stay visible fill-ins.
    $response->assertSee(__('snapshot name'))
        ->assertSee(__('persona'));

    // Diagrams are drawn inline — real SVG, no markdown/mermaid residue.
    expect(substr_count($response->getContent(), '<svg'))->toBeGreaterThanOrEqual(2);
    $response->assertSee('System overview: GoHighLevel handles conversation')
        ->assertDontSee('language-mermaid')
        ->assertDontSee('sequenceDiagram');
});

it('opens the first doc by default and 404s unknown slugs', function () {
    $actor = docsAgencyUser(AgencyRole::Admin);

    // No slug: lands on the first registry doc.
    $this->actingAs($actor)->get(route('agency.docs'))
        ->assertOk()
        ->assertSee(__('Before you start'));

    $this->actingAs($actor)->get(route('agency.docs', ['doc' => 'no-such-doc']))->assertNotFound();

    expect((new AgencyDocs)->find('../../secrets'))->toBeNull();
});

it('keeps the registry and the rendered sections in lockstep', function () {
    $actor = docsAgencyUser(AgencyRole::Owner);
    $registry = new AgencyDocs;

    foreach ($registry->groups() as $group) {
        foreach ($group['docs'] as $entry) {
            $doc = $registry->find($entry['slug']);
            $response = $this->actingAs($actor)
                ->get(route('agency.docs', ['doc' => $entry['slug']]))
                ->assertOk();

            // Every registered section anchor exists in the rendered page —
            // the on-page nav can never point at a missing anchor.
            foreach ($doc['sections'] as $section) {
                $response->assertSee('id="'.$section['id'].'"', false);
            }
        }
    }
});

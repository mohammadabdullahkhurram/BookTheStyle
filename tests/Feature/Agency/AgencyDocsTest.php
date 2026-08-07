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

it('renders the SOP at industry depth: purpose, prerequisites, decision points, verification, troubleshooting, escalation, checklists', function () {
    $response = $this->actingAs(docsAgencyUser(AgencyRole::User))
        ->get(route('agency.docs', ['doc' => 'salon-setup-sop']))
        ->assertOk();

    // The full section structure, anchored, with the on-page nav.
    foreach (['purpose', 'before-you-start', 'part-a-bookthestyle', 'part-b-ghl', 'part-c-connect', 'verification', 'troubleshooting', 'escalation', 'checklist'] as $id) {
        $response->assertSee('id="'.$id.'"', false);
    }
    $response->assertSee(__('On this page'))
        ->assertSee(__('Purpose & scope'))
        ->assertSee(__('Final verification — the full pass'))
        ->assertSee(__('Troubleshooting — if you see X, do Y'))
        ->assertSee(__('Escalation — who to ask'))
        ->assertSee(__('Go-live checklist'));

    // Steps carry expected results and decision points; kit components render.
    $response->assertSee(__('How you know it worked:'))
        ->assertSee(__('Choose the salon type — a decision point'))
        ->assertSee(__('Important'))
        ->assertSee(__('Screenshot to add'))
        ->assertSee('Takes bookings');

    // Chair Rental is the terminology — the old term is gone.
    $response->assertSee('Chair Rental')
        ->assertDontSee('Booth');

    // GHL-instance specifics render as visible fill-in slots.
    $response->assertSee(__('Loopflo snapshot name'));
});

it('renders the technical reference at industry depth: endpoint/parameter/error tables, token lifecycle, webhook, runbook, glossary', function () {
    $response = $this->actingAs(docsAgencyUser(AgencyRole::Owner))
        ->get(route('agency.docs', ['doc' => 'technical-integration-reference']))
        ->assertOk();

    // The full section structure, anchored.
    foreach (['about', 'prerequisites', 'architecture', 'booking-sequence', 'endpoints', 'auth', 'resilience', 'webhook', 'ghl-side', 'runbook', 'troubleshooting', 'glossary', 'sync'] as $id) {
        $response->assertSee('id="'.$id.'"', false);
    }

    // Per-endpoint reference: paths, route names, request examples, and the
    // error table with real codes — all verified from the codebase.
    $response->assertSee('/api/v1/booking/availability')
        ->assertSee('/api/v1/booking/create')
        ->assertSee('api.booking.availability')
        ->assertSee(__('Example request:'))
        ->assertSee(__('Status codes &amp; error responses'), false)
        ->assertSee('slot_unavailable')
        ->assertSee('unknown_service')
        ->assertSee('ambiguous_stylist')
        ->assertSee('invalid_request');

    // Token lifecycle + resilience + webhook specifics.
    $response->assertSee('btsk_', false)
        ->assertSee('Authorization: Bearer')
        ->assertSee(__('Rotation / revocation'))
        ->assertSee(__('Idempotent create'))
        ->assertSee('60')
        ->assertSee('X-Webhook-Secret')
        ->assertSee('webhooks.ghl');

    // Runbook with expected outcomes, glossary, and the sync note.
    $response->assertSee(__('Expected:'))
        ->assertSee(__('Glossary'))
        ->assertSee('Chair Rental')
        ->assertSee(__('Keeping this page in sync with the code'));

    // GHL-instance specifics stay visible fill-ins; no old terminology.
    $response->assertSee(__('snapshot name'))
        ->assertSee(__('persona'))
        ->assertDontSee('Booth');

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

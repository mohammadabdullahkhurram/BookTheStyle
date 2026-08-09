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

it('renders the rebuilt SOP: guided parts, expected results, decision points, day-to-day, checklists', function () {
    $response = $this->actingAs(docsAgencyUser(AgencyRole::User))
        ->get(route('agency.docs', ['doc' => 'salon-setup-sop']))
        ->assertOk();

    // The full section structure, anchored, with the on-page nav.
    foreach (['how-it-works', 'before-you-start', 'part-a-bookthestyle', 'part-b-salon-setup', 'part-c-ghl', 'part-d-connect', 'part-e-voice', 'part-f-widget', 'verify-go-live', 'day-to-day', 'troubleshooting', 'escalation', 'checklist'] as $id) {
        $response->assertSee('id="'.$id.'"', false);
    }
    $response->assertSee(__('On this page'))
        ->assertSee(__('How the system fits together'))
        ->assertSee(__('Test everything & go live'))
        ->assertSee(__('Running the salon day to day'))
        ->assertSee(__('Troubleshooting — if you see X, do Y'))
        ->assertSee(__('Escalation — who to ask'))
        ->assertSee(__('Go-live checklist'));

    // Steps carry expected results and decision points; the kit renders.
    $response->assertSee(__('How you know it worked:'))
        ->assertSee(__('Choose the salon type — a decision point'))
        ->assertSee(__('Important'))
        ->assertSee(__('Screenshot to add'))
        ->assertSee('Takes bookings');

    // The load-bearing operational facts, spelled out for the team.
    $response->assertSee('X-Webhook-Secret')
        ->assertSee('/v1/')
        ->assertSee('Bluejaypro Voice AI Test Client')
        ->assertSee('+1 555 010 0001')
        ->assertSee('28 June 3004');

    // Chair Rental is the terminology — the old term is gone.
    $response->assertSee('Chair Rental')
        ->assertDontSee('Booth');

    // GHL-instance specifics render as visible fill-in slots; an SVG
    // system-overview diagram is drawn inline.
    $response->assertSee(__('Loopflo snapshot name'));
    expect(substr_count($response->getContent(), '<svg'))->toBeGreaterThanOrEqual(1);
});

it('renders the rebuilt technical reference: engine, all four endpoints, webhook, sync, health, config — from the code', function () {
    $response = $this->actingAs(docsAgencyUser(AgencyRole::Owner))
        ->get(route('agency.docs', ['doc' => 'technical-integration-reference']))
        ->assertOk();

    // The full section structure, anchored.
    foreach (['about', 'architecture', 'domain-model', 'booking-engine', 'endpoints', 'auth', 'webhook', 'outbound-sync', 'surfaces', 'health', 'security-ops', 'ghl-side', 'runbook', 'troubleshooting', 'glossary', 'sync'] as $id) {
        $response->assertSee('id="'.$id.'"', false);
    }

    // All FOUR endpoints with route names, examples, and the real error codes.
    foreach (['availability', 'create', 'cancel', 'reschedule'] as $endpoint) {
        $response->assertSee('/api/v1/booking/'.$endpoint)
            ->assertSee('api.booking.'.$endpoint);
    }
    $response->assertSee(__('Example request:'))
        ->assertSee('slot_unavailable')
        ->assertSee('multiple_appointments')
        ->assertSee('cannot_cancel')
        ->assertSee('client_not_found')
        ->assertSee('unknown_service')
        ->assertSee('ambiguous_stylist')
        ->assertSee('invalid_request');

    // Token scheme, webhook contract, engine and sync specifics.
    $response->assertSee('btsk_', false)
        ->assertSee('Authorization: Bearer')
        ->assertSee(__('Rotation / revocation'))
        ->assertSee('X-Webhook-Secret')
        ->assertSee('webhooks.ghl')
        ->assertSee('ignored_echo')
        ->assertSee('ghl_last_pushed_status')
        ->assertSee('max_advance_days')
        ->assertSee('SlotEngine');

    // Health/test-lane facts and the config/limit tables.
    $response->assertSee('28 June 3004')
        ->assertSee('+1 555 010 0001')
        ->assertSee('health:monitor')
        ->assertSee('calendars/events.write')
        ->assertSee(__('60/min (BOOKING_API_RATE_LIMIT)'));

    // Runbook with expected outcomes, glossary, and the sync note.
    $response->assertSee(__('Expected:'))
        ->assertSee(__('Glossary'))
        ->assertSee('Chair Rental')
        ->assertSee(__('Keeping this page in sync with the code'));

    // GHL-instance specifics stay visible fill-ins; no old terminology.
    $response->assertSee(__('snapshot name'))
        ->assertSee(__('persona'))
        ->assertDontSee('Booth');

    // Diagrams are real inline SVG — at least the system overview and the
    // status machine; no markdown/mermaid residue.
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

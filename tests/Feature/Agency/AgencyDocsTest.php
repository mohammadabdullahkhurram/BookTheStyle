<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Support\AgencyDocs;

/*
| The agency Documentation tab: markdown docs from resources/docs, rendered
| for EVERY agency role — and for nobody else. Salon users and guests are
| refused server-side; the demo host never carries the route at all.
*/

function docsAgencyUser(AgencyRole $role): User
{
    return User::factory()->create([
        'agency_id' => Agency::factory()->create()->id,
        'agency_role' => $role,
    ]);
}

it('renders the Documentation tab with the doc index for every agency role', function () {
    foreach ([AgencyRole::Owner, AgencyRole::Admin, AgencyRole::User] as $role) {
        $this->actingAs(docsAgencyUser($role))
            ->get(route('agency.docs'))
            ->assertOk()
            ->assertSee(__('Documentation'))
            ->assertSee('Salon onboarding + GHL & Voice AI setup')
            ->assertSee('SOPs');
    }
});

it('blocks salon users server-side and bounces guests; the demo host has no docs route', function () {
    $salon = Salon::factory()->create();

    // Salon roles: authenticated but refused — for the index AND a doc URL.
    foreach ([salonOwnerOf($salon), salonAdminOf($salon), stylistOf($salon)] as $actor) {
        $this->actingAs($actor)->get(route('agency.docs'))->assertForbidden();
    }
    $this->actingAs(salonOwnerOf($salon))
        ->get(route('agency.docs', ['doc' => 'salon-onboarding-ghl-voice-ai']))
        ->assertForbidden();

    // The demo guest's host simply has no such route — 404 at the boundary.
    $this->get('http://demo.'.config('app.domain').'/agency/docs')->assertNotFound();
});

it('bounces unauthenticated guests to login', function () {
    $this->get(route('agency.docs'))->assertRedirect(route('login'));
});

it('renders a doc\'s markdown properly: anchored headings, image, code block, table', function () {
    $response = $this->actingAs(docsAgencyUser(AgencyRole::Owner))
        ->get(route('agency.docs', ['doc' => 'salon-onboarding-ghl-voice-ai']))
        ->assertOk();

    // Anchored heading, embedded image from the docs-asset location, fenced
    // code block, and a GFM table — all rendered as HTML.
    $response->assertSee('<h2>', false)
        ->assertSee('id="overview"', false)
        ->assertSee('<img src="/docs-assets/onboarding-flow.png"', false)
        ->assertSee('<pre><code', false)
        ->assertSee('<table>', false)
        ->assertSee('Private Integration Token');

    // The referenced image genuinely exists at the asset location.
    expect(file_exists(public_path('docs-assets/onboarding-flow.png')))->toBeTrue();
});

it('surfaces a newly committed markdown file in the index — and drops raw HTML on render', function () {
    $path = resource_path('docs/zz-pest-temp-doc.md');
    file_put_contents($path, <<<'MD'
---
title: Temp doc for Pest
category: Testing
order: 5
---

## Hello

<script>alert('nope')</script>

A paragraph.
MD);

    try {
        $this->actingAs(docsAgencyUser(AgencyRole::Admin))
            ->get(route('agency.docs'))
            ->assertOk()
            ->assertSee('Temp doc for Pest')
            ->assertSee('Testing');

        $rendered = (new AgencyDocs)->find('zz-pest-temp-doc');
        expect($rendered['title'])->toBe('Temp doc for Pest');
        expect($rendered['html'])->toContain('id="hello"');
        expect($rendered['html'])->not->toContain('<script>'); // html_input strip
    } finally {
        unlink($path);
    }
});

it('404s unknown and traversal-shaped doc slugs', function () {
    $actor = docsAgencyUser(AgencyRole::Owner);

    $this->actingAs($actor)->get(route('agency.docs', ['doc' => 'no-such-doc']))->assertNotFound();

    expect((new AgencyDocs)->find('../../config/app'))->toBeNull();
    expect((new AgencyDocs)->find('..'))->toBeNull();
});

it('lists and renders the SOP and technical reference docs — agency-only as ever', function () {
    $agencyUser = docsAgencyUser(AgencyRole::User);

    // Both docs sit in the index, under their groups.
    $this->actingAs($agencyUser)
        ->get(route('agency.docs'))
        ->assertOk()
        ->assertSee('How to set up a new salon — step by step')
        ->assertSee('BookTheStyle × GoHighLevel — technical integration reference')
        ->assertSee('Technical')
        ->assertSee('SOPs');

    // Each renders.
    $this->actingAs($agencyUser)
        ->get(route('agency.docs', ['doc' => 'salon-setup-sop']))
        ->assertOk()
        ->assertSee('Before you start — gather these first');

    // And the boundary holds for the new docs too.
    $salon = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($salon))
        ->get(route('agency.docs', ['doc' => 'technical-integration-reference']))
        ->assertForbidden();
    $this->get('http://demo.'.config('app.domain').'/agency/docs/technical-integration-reference')
        ->assertNotFound();
});

it('ships the technical doc with BTS-side values filled and GHL-side slots left open', function () {
    $response = $this->actingAs(docsAgencyUser(AgencyRole::Owner))
        ->get(route('agency.docs', ['doc' => 'technical-integration-reference']))
        ->assertOk();

    // BookTheStyle-side facts, verified from the codebase — no longer {{ }}.
    $response->assertSee('POST', false)
        ->assertSee('/api/v1/booking/availability')
        ->assertSee('/api/v1/booking/create')
        ->assertSee('api.booking.availability')
        ->assertSee('btsk_')
        ->assertSee('Authorization: Bearer')
        ->assertSee('Voice-AI Booking API');

    // GHL-side blanks stay as slots — they live in GHL, not this repo.
    $response->assertSee('{{ snapshot name }}')
        ->assertSee('[📸 capture each from the in-app integration settings so the payloads are exact]');

    // No BTS-side endpoint/auth placeholder survived.
    $response->assertDontSee('{{ METHOD + PATH }}')
        ->assertDontSee('{{ token / header scheme');
});

it('renders mermaid fences as diagram hooks, not raw text', function () {
    $response = $this->actingAs(docsAgencyUser(AgencyRole::Owner))
        ->get(route('agency.docs', ['doc' => 'technical-integration-reference']))
        ->assertOk();

    // The fence becomes a language-tagged code block — the exact hook the
    // client-side renderer swaps for the drawn SVG (two diagrams in this doc).
    $response->assertSee('<code class="language-mermaid">', false)
        ->assertSee('sequenceDiagram')
        ->assertSee('flowchart LR');

    expect(substr_count($response->getContent(), 'language-mermaid'))->toBe(2);
});

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

<?php

use App\Models\Salon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/*
| The demo's INTERACTIVE surface, in the guest model. Page loads resolve the
| showcase salon via ResolveSalon; every Livewire interaction goes through
| the update endpoint, where ResolveSalon — as a PERSISTENT middleware —
| re-resolves the showcase and re-installs the request-scoped viewer. These
| tests pin that whole path: a browser-faithful commit must never 404 (the
| original first-click regression) and must never require a login.
*/

/** Lift a component snapshot from rendered HTML exactly as the browser would. */
function firstSnapshotOf(string $html): string
{
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    expect($matches)->not->toBeEmpty('page contains no Livewire snapshot');

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
}

/** POST a browser-faithful Livewire commit for the given page. */
function livewireCommit($test, string $host, string $page)
{
    $html = $test->get($host.$page)->assertOk()->getContent();

    return $test->postJson($host.'/'.ltrim(EndpointResolver::updatePath(), '/'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => firstSnapshotOf($html),
            'updates' => (object) [],
            'calls' => [],
        ]],
    ], ['X-Livewire' => '1']);
}

it('keeps Livewire interactions working on the demo host as a guest (the first-click 404)', function () {
    demoShowcase();

    $host = 'http://demo.'.config('app.domain');

    // The landing calendar and the dashboard: first clicks must commit.
    livewireCommit($this, $host, '/')->assertOk();
    livewireCommit($this, $host, '/calendar')->assertOk();
});

it('keeps Livewire interactions working on a REAL salon subdomain (control)', function () {
    $salon = Salon::factory()->create();

    $this->actingAs(salonOwnerOf($salon));

    livewireCommit($this, 'http://'.$salon->slug.'.'.config('app.domain'), '/calendar')->assertOk();
});

it('walks EVERY demo page as a guest: load AND interaction succeed, zero outbound HTTP or mail', function () {
    $salon = demoShowcase();

    Http::preventStrayRequests();
    Mail::fake();

    $host = 'http://demo.'.config('app.domain');
    $client = $salon->clients()->firstOrFail();

    $pages = [
        '/', '/calendar', '/appointments', '/appointments/all', '/book',
        '/clients', '/clients/'.$client->id, '/users', '/services',
        '/availability', '/reports', '/settings', '/widgets', '/account', '/setup',
    ];

    foreach ($pages as $page) {
        livewireCommit($this, $host, $page)->assertOk();
    }

    // The interaction round-trip resolved the SHOWCASE, not slug "demo".
    expect(app('currentSalon')->id)->toBe($salon->id);

    Mail::assertNothingOutgoing();
});

it('keeps every link, form action, and Livewire endpoint on static hosts', function () {
    demoShowcase();

    $domain = config('app.domain');
    $host = 'http://demo.'.$domain;

    foreach (['/', '/calendar', '/book', '/widgets', '/settings'] as $page) {
        $html = $this->get($host.$page)->assertOk()->getContent();

        // Every generated app URL must stay on an allowlisted static host —
        // never the showcase salon's raw slug (an unroutable hostname).
        preg_match_all('/(?:href|action)="(https?:\/\/[^"\/]+)/', $html, $matches);
        $hosts = array_unique(array_map(fn ($url) => parse_url($url, PHP_URL_HOST), $matches[1]));

        foreach ($hosts as $found) {
            expect(in_array($found, ['demo.'.$domain, 'app.'.$domain, 'register.'.$domain, $domain], true))
                ->toBeTrue("page {$page} links to non-static host {$found}");
        }
    }
});

it('shows the widgets page in demo with the inline preview and inert embeds', function () {
    demoShowcase();

    $this->get('http://demo.'.config('app.domain').'/widgets')
        ->assertOk()
        ->assertSee('/widgets/preview/', false)
        ->assertSee(__('Embeds are switched off in the demo. In your real salon, this code drops the booking form into any website.'))
        // No clickable door to the (structurally inert → 404) public widget
        // host; the embed snippets stay as entity-encoded copy-text.
        ->assertDontSee('href="http://demo.'.config('app.domain').'/widget/', false);
});

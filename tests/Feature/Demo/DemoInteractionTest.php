<?php

use App\Models\Salon;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/*
| The demo's INTERACTIVE surface. Initial page loads always worked (ResolveSalon
| rebinds the literal "demo" host slug to the session's salon before implicit
| binding runs) — but every Livewire interaction goes through the update
| endpoint, where Livewire replays the original path through its persistent
| middleware (SubstituteBindings included, resolve.salon NOT included). There,
| implicit binding sees the raw {salon} domain param "demo", finds no such
| slug, and 404s: the classic "demo renders, breaks on first click".
*/

function enterDemo($test): Salon
{
    $test->get('http://app.'.config('app.domain').'/demo')->assertRedirect();

    return Salon::query()
        ->whereKey(session('demo_salon_id'))
        ->where('is_demo', true)
        ->firstOrFail();
}

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

it('keeps Livewire interactions working on the demo host (the first-click 404)', function () {
    enterDemo($this);

    $host = 'http://demo.'.config('app.domain');

    // The dashboard: the page the visitor lands on, and their first click.
    livewireCommit($this, $host, '/')->assertOk();

    // The calendar: mount(Salon $salon) — the strictest binding surface.
    livewireCommit($this, $host, '/calendar')->assertOk();
});

it('keeps Livewire interactions working on a REAL salon subdomain (control)', function () {
    $salon = Salon::factory()->create();

    $this->actingAs(salonOwnerOf($salon));

    livewireCommit($this, 'http://'.$salon->slug.'.'.config('app.domain'), '/calendar')->assertOk();
});

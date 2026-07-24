<?php

use App\Models\Booking;
use App\Models\Salon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
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

it('walks EVERY demo page: initial load AND a Livewire interaction both succeed — with zero outbound HTTP or mail', function () {
    $salon = enterDemo($this);

    // The inertness net over the ENTIRE walked surface: any GHL (or other)
    // outbound call from any page or interaction throws; any mail fails below.
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

    // The interaction round-trip binds the SESSION's salon, not slug "demo".
    expect(app('currentSalon')->id)->toBe($salon->id);

    Mail::assertNothingOutgoing();
});

it('books end to end inside the demo: service → stylist → slot → details → saved, inertly', function () {
    $salon = enterDemo($this);

    Http::preventStrayRequests();
    Mail::fake();

    // A seeded service with a qualified stylist — the pair a visitor would pick.
    $service = $salon->services()->whereHas('stylists')->firstOrFail();
    $stylist = $service->stylists()->firstOrFail();

    $component = Livewire::actingAs(auth()->user())
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('clientMode', 'new')
        ->set('newName', 'Walkthrough Guest')
        ->set('items.0.service_id', (string) $service->id)
        ->set('items.0.stylist_id', (string) $stylist->id);

    // Find a day with genuinely open slots (seeded calendars are busy).
    $slot = null;
    foreach (range(1, 14) as $ahead) {
        $date = now($salon->timezone)->addDays($ahead)->format('Y-m-d');
        $component->set('date', $date);
        $slots = $component->instance()->slotsForLine(0);
        if ($slots !== []) {
            $slot = $slots[0]['value'] ?? $slots[0]['time'] ?? array_values($slots)[0];

            break;
        }
    }
    expect($slot)->not->toBeNull('no open slot found in the next 14 days of the demo calendar');

    $component->call('pickTime', 0, is_array($slot) ? $slot['time'] : $slot)
        ->call('save')
        ->assertHasNoErrors();

    $booking = Booking::withoutGlobalScopes()
        ->where('salon_id', $salon->id)
        ->whereHas('client', fn ($q) => $q->where('name', 'Walkthrough Guest'))
        ->first();
    expect($booking)->not->toBeNull();

    // The confirmation surface: the new booking shows on the demo host pages.
    $host = 'http://demo.'.config('app.domain');
    $this->get($host.'/appointments/all')->assertOk()->assertSee('Walkthrough Guest');

    Mail::assertNothingOutgoing();
});

it('keeps every link, form action, and Livewire endpoint on the demo host', function () {
    enterDemo($this);

    $domain = config('app.domain');
    $host = 'http://demo.'.$domain;

    foreach (['/', '/calendar', '/book', '/widgets', '/settings'] as $page) {
        $html = $this->get($host.$page)->assertOk()->getContent();

        // Every generated app URL must stay on an allowlisted static host —
        // never the demo salon's raw slug (an unroutable hostname).
        preg_match_all('/(?:href|action)="(https?:\/\/[^"\/]+)/', $html, $matches);
        $hosts = array_unique(array_map(fn ($url) => parse_url($url, PHP_URL_HOST), $matches[1]));

        // The static, human-created host allowlist (HostnameGuardTest pins
        // the same set for routes) — never a demo salon's raw slug.
        foreach ($hosts as $found) {
            expect(in_array($found, ['demo.'.$domain, 'app.'.$domain, 'register.'.$domain, $domain], true))
                ->toBeTrue("page {$page} links to non-static host {$found}");
        }
    }
});

it('never leaks one visitor\'s demo bookings into another\'s pages', function () {
    $first = enterDemo($this);
    $marker = 'Zebra Isolation-Marker';
    $firstClient = $first->clients()->firstOrFail();
    $firstClient->forceFill(['name' => $marker])->save();

    // A brand-new visitor: fresh session, fresh salon, fresh data.
    $this->post(route('logout'));
    $this->flushSession();
    $second = enterDemo($this);
    expect($second->id)->not->toBe($first->id);

    $host = 'http://demo.'.config('app.domain');
    $this->get($host.'/clients')->assertOk()->assertDontSee($marker);
    $this->get($host.'/appointments/all')->assertOk()->assertDontSee($marker);
});

it('shows the widgets page in demo without the dead preview link', function () {
    enterDemo($this);

    $this->get('http://demo.'.config('app.domain').'/widgets')
        ->assertOk()
        ->assertSee(__('Preview is disabled in the demo'))
        ->assertSee(__('Embeds are switched off in the demo. In your real salon, this code drops the booking form into any website.'))
        // No clickable door to the (structurally inert → 404) public widget.
        // The embed SNIPPETS stay visible as labeled, entity-encoded copy-text.
        ->assertDontSee('href="http://demo.'.config('app.domain').'/widget/', false);
});

<?php

use App\Enums\BookedByType;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\Service;
use App\Models\User;
use App\Services\Calendar\CalendarFeedService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
| Phase 5 — personal ICS calendar feeds. Token model, the /cal/{token}.ics
| endpoint, ICS correctness, scope/isolation, and the account UI panel.
*/

/**
 * Create a booking with one item assigned to $stylist in $salon.
 *
 * @param  array<string, mixed>  $attrs  booking overrides (status, booked_by_type…)
 */
function stylistBooking(
    User $stylist,
    Salon $salon,
    array $attrs = [],
    string $clientName = 'Casey Client',
    string $serviceName = 'Cut & Style',
    CarbonImmutable|string|null $start = null,
    ?CarbonImmutable $end = null,
): Booking {
    $client = Client::factory()->for($salon)->create(['name' => $clientName]);
    $service = Service::factory()->for($salon)->create(['name' => $serviceName]);

    $booking = Booking::factory()->for($salon)->for($client)->create(array_merge([
        'status' => BookingStatus::Confirmed,
        'booked_by_type' => BookedByType::FrontDesk,
    ], $attrs));

    $start = $start instanceof CarbonImmutable ? $start
        : CarbonImmutable::parse($start ?? CarbonImmutable::now('UTC')->addDays(2)->setTime(14, 0)->toDateTimeString());
    $end ??= $start->addHour();

    BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => $service->id,
        'stylist_id' => $stylist->id,
        'starts_at' => $start,
        'ends_at' => $end,
    ]);

    return $booking;
}

/** Generate a feed token for the user and return its public subscribe URL. */
function feedUrlFor(User $user): string
{
    $token = app(CalendarFeedService::class)->regenerate($user);

    return route('cal.feed', ['token' => $token]);
}

it('serves well-formed ICS for a valid token', function () {
    $salon = Salon::factory()->create(['name' => 'Glow Bar']);
    $stylist = stylistOf($salon);
    $booking = stylistBooking($stylist, $salon);

    $response = $this->get(feedUrlFor($stylist));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

    $body = $response->getContent();
    expect($body)->toContain('BEGIN:VCALENDAR');
    expect($body)->toContain('VERSION:2.0');
    expect($body)->toContain('BEGIN:VEVENT');
    expect($body)->toContain('UID:bts-booking-'.$booking->id.'@'.config('app.domain'));
    expect($body)->toContain('SUMMARY:Casey Client');
    expect($body)->toContain('Cut & Style');
    expect($body)->toContain('LOCATION:Glow Bar');
    expect($body)->toContain('STATUS:CONFIRMED');
    expect($body)->toContain('END:VEVENT');
    expect($body)->toContain('END:VCALENDAR');
    expect($body)->toMatch('/DTSTART:\d{8}T\d{6}Z/');
    expect($body)->toMatch('/DTEND:\d{8}T\d{6}Z/');
});

it('keeps a stable UID across fetches', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $booking = stylistBooking($stylist, $salon);
    $url = feedUrlFor($stylist);

    $uid = 'UID:bts-booking-'.$booking->id.'@'.config('app.domain');
    expect($this->get($url)->getContent())->toContain($uid);
    expect($this->get($url)->getContent())->toContain($uid);
});

it('404s an unknown token without revealing validity', function () {
    $this->get(route('cal.feed', ['token' => str_repeat('a', 64)]))->assertNotFound();
});

it('invalidates the old URL when the token is regenerated, and on revoke', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    stylistBooking($stylist, $salon);
    $service = app(CalendarFeedService::class);

    $first = route('cal.feed', ['token' => $service->regenerate($stylist)]);
    $this->get($first)->assertOk();

    // Rotate → old URL dies, new works.
    $second = route('cal.feed', ['token' => $service->regenerate($stylist)]);
    $this->get($first)->assertNotFound();
    $this->get($second)->assertOk();

    // Revoke → new URL dies too.
    $service->revoke($stylist);
    $this->get($second)->assertNotFound();
});

it('includes only the user\'s own bookings — no cross-user or cross-salon leak', function () {
    $salon = Salon::factory()->create();
    $other = Salon::factory()->create();

    $me = stylistOf($salon);
    $coworker = stylistOf($salon);
    $elsewhere = stylistOf($other);

    $mine = stylistBooking($me, $salon, clientName: 'My Client');
    $theirs = stylistBooking($coworker, $salon, clientName: 'Coworker Client');
    $foreign = stylistBooking($elsewhere, $other, clientName: 'Other Salon Client');

    $body = $this->get(feedUrlFor($me))->getContent();

    expect($body)->toContain('bts-booking-'.$mine->id.'@');
    expect($body)->toContain('My Client');
    expect($body)->not->toContain('bts-booking-'.$theirs->id.'@');
    expect($body)->not->toContain('Coworker Client');
    expect($body)->not->toContain('bts-booking-'.$foreign->id.'@');
    expect($body)->not->toContain('Other Salon Client');
});

it('spans multiple salons in one feed, each event named with its salon', function () {
    $salonA = Salon::factory()->create(['name' => 'Salon Aurora']);
    $salonB = Salon::factory()->create(['name' => 'Salon Borealis']);
    $user = User::factory()->create();
    // Same person is a stylist in two salons.
    SalonMembership::factory()->for($user)->for($salonA)->stylist()->create();
    SalonMembership::factory()->for($user)->for($salonB)->stylist()->create();

    stylistBooking($user, $salonA, clientName: 'Aurora Client');
    stylistBooking($user, $salonB, clientName: 'Borealis Client');

    $body = $this->get(feedUrlFor($user))->getContent();
    expect($body)->toContain('LOCATION:Salon Aurora');
    expect($body)->toContain('LOCATION:Salon Borealis');
    expect($body)->toContain('Aurora Client');
    expect($body)->toContain('Borealis Client');
});

it('marks a cancelled booking CANCELLED and bumps SEQUENCE on update with the same UID', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC'));

    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $booking = stylistBooking($stylist, $salon, ['status' => BookingStatus::Confirmed]);
    $url = feedUrlFor($stylist);

    $before = $this->get($url)->getContent();
    expect($before)->toContain('STATUS:CONFIRMED');
    preg_match('/SEQUENCE:(\d+)/', $before, $m);
    $seqBefore = (int) $m[1];

    // Later, the booking is cancelled.
    $this->travelTo(CarbonImmutable::parse('2026-09-01 12:05:00', 'UTC'));
    $booking->update(['status' => BookingStatus::Cancelled]);

    $after = $this->get($url)->getContent();
    expect($after)->toContain('STATUS:CANCELLED');
    expect($after)->toContain('UID:bts-booking-'.$booking->id.'@'.config('app.domain'));
    preg_match('/SEQUENCE:(\d+)/', $after, $m2);
    expect((int) $m2[1])->toBeGreaterThan($seqBefore);

    $this->travelBack();
});

it('emits DST-correct UTC times for the salon timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC'));

    $salon = Salon::factory()->create(['timezone' => 'America/New_York']);
    $stylist = stylistOf($salon);

    // 10:00 local during EDT (-4) → 14:00Z; during EST (-5) → 15:00Z.
    stylistBooking($stylist, $salon, clientName: 'Summer',
        start: CarbonImmutable::parse('2026-10-01 10:00', 'America/New_York'));
    stylistBooking($stylist, $salon, clientName: 'Winter',
        start: CarbonImmutable::parse('2026-12-01 10:00', 'America/New_York'));

    $body = $this->get(feedUrlFor($stylist))->getContent();
    expect($body)->toContain('DTSTART:20261001T140000Z'); // EDT
    expect($body)->toContain('DTSTART:20261201T150000Z'); // EST

    $this->travelBack();
});

it('sets cache and rate-limit headers, and supports conditional GET', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    stylistBooking($stylist, $salon);
    $url = feedUrlFor($stylist);

    $response = $this->get($url);
    $response->assertOk();
    // Symfony may reorder Cache-Control directives, so assert on the directives.
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=900');
    $response->assertHeader('X-RateLimit-Limit', 60);
    expect($response->headers->get('ETag'))->not->toBeNull();

    // Conditional GET with the same ETag → 304 Not Modified.
    $etag = $response->headers->get('ETag');
    $this->get($url, ['If-None-Match' => $etag])->assertStatus(304);
});

it('excludes bookings outside the feed window', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    $old = stylistBooking($stylist, $salon, clientName: 'Long Ago',
        start: CarbonImmutable::now('UTC')->subMonths(2));
    $soon = stylistBooking($stylist, $salon, clientName: 'Soon',
        start: CarbonImmutable::now('UTC')->addDays(3));

    $body = $this->get(feedUrlFor($stylist))->getContent();
    expect($body)->toContain('Soon');
    expect($body)->not->toContain('bts-booking-'.$old->id.'@');
});

it('lets a user generate, view and revoke their feed from the account panel', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    stylistBooking($stylist, $salon);
    $this->actingAs($stylist);

    $component = Livewire::test('pages::settings.calendar-feed')
        ->assertSet('connected', false)
        ->call('generate')
        ->assertSet('connected', true);

    $url = $component->get('subscribeUrl');
    expect($url)->toContain('/cal/');
    $this->get($url)->assertOk();

    $component->call('revoke')->assertSet('connected', false)->assertSet('subscribeUrl', null);
    $this->get($url)->assertNotFound();
});

it('leads with the copyable link, per-app steps, and an honestly-labelled Apple shortcut', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(stylistOf($salon));

    Livewire::test('pages::settings.calendar-feed')
        ->call('generate')
        // Step 1: the link is front and centre with a working copy affordance
        // (Clipboard API + an execCommand fallback for non-secure dev hosts),
        // and the copy confirmation is announced via a live region.
        ->assertSee('Step 1 — copy your calendar link')
        ->assertSee('Copy link')
        ->assertSeeHtml('navigator.clipboard.writeText')
        ->assertSeeHtml('document.execCommand')
        ->assertSeeHtml('x-ref="feedUrl"')
        ->assertSeeHtml('role="status" aria-live="polite"')
        // Step 2: one short recipe per app — paste is the only way in
        // Google/Outlook, and that is said out loud.
        ->assertSee('Step 2 — paste it into your calendar app')
        ->assertSee('Google Calendar')
        ->assertSee('Apple Calendar')
        ->assertSee('Outlook')
        ->assertSee('pasting is the only way')
        // The Apple shortcut is a real webcal:// link with an honest label —
        // the ambiguous "Subscribe on this device" is gone.
        ->assertSee('Open in Apple Calendar')
        ->assertSeeHtml('href="webcal://')
        ->assertDontSee('Subscribe on this device')
        // Expectations: read-only, one-way, apps refresh on their own clock.
        ->assertSee('Read-only and one-way')
        ->assertSee('Google can take several hours to show changes');
});

it('compiles the copy control cleanly — the handler reaches the browser as real JS', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(stylistOf($salon));

    $html = Livewire::test('pages::settings.calendar-feed')
        ->call('generate')
        ->html();

    // The prior copy-button bug shipped uncompiled Blade into Alpine — guard
    // against every form of it (see the x-ui.confirm-modal recipe).
    expect($html)->not->toContain('@js(')
        ->and($html)->not->toContain('Js::from')
        ->and($html)->toContain('x-on:click="copy()"');

    // The input the fallback selects exists, and the feed URL is inside it.
    expect($html)->toContain('x-ref="feedUrl"');
    preg_match('/x-ref="feedUrl"[^>]*value="([^"]+)"/', $html, $m);
    expect($m[1] ?? '')->toContain('/cal/');
});

it('keeps regenerate and revoke on the themed confirm modal after the redesign', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(stylistOf($salon));

    Livewire::test('pages::settings.calendar-feed')
        ->call('generate')
        ->assertSeeHtml('$store.confirm.ask')
        ->assertDontSeeHtml('wire:confirm')
        ->assertSee('Regenerate the link? Your existing calendar subscription will stop updating until you re-add the new link.');
});

it('notes that Google/Outlook can only reach the link once the app is live, on local URLs', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(stylistOf($salon));

    // The test APP_URL is a local host, so the note must show…
    config(['app.url' => 'http://app.lvh.me:8000']);
    $page = Livewire::test('pages::settings.calendar-feed')->call('generate');
    $page->assertSee('once the app runs on its live URL');

    // …and disappear on a public URL (nothing looks broken either way).
    config(['app.url' => 'https://app.bookthestyle.com']);
    $page->call('$refresh')->assertDontSee('once the app runs on its live URL');
});

it('hosts the personal calendar panel salon-side (My calendar), not on the profile page', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    // Moved OUT of the account/profile settings on the app host…
    $this->actingAs($stylist)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('Personal calendar');

    // …and INTO the salon-side My calendar page, open to every member.
    $this->actingAs($stylist)
        ->get(route('salon.account', $salon))
        ->assertOk()
        ->assertSee('My calendar')
        ->assertSee('Personal calendar');

    // Non-members never reach it (membership enforced by ResolveSalon).
    $outsider = stylistOf(Salon::factory()->create());
    $this->actingAs($outsider)->get(route('salon.account', $salon))->assertForbidden();
});

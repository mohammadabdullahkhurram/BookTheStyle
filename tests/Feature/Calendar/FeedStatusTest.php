<?php

use App\Models\User;
use App\Services\Calendar\CalendarFeedService;
use Livewire\Livewire;

/*
| The feed's "connection status" is EVIDENCE, not a probe: the feed is
| pull-based, so the only honest signal is that a calendar app actually
| fetched it. Recording that must stay cheap — the endpoint is public and
| clients poll — so the write is throttled to one per five-minute window.
*/

const GOOGLE_UA = 'Google-Calendar-Importer';
const APPLE_UA = 'macOS/14.5 (23F79) CalendarAgent/1042';

function feedFor(User $user): array
{
    $token = app(CalendarFeedService::class)->regenerate($user);

    return [$token, route('cal.feed', ['token' => $token])];
}

it('records the fetch — timestamp, parsed client, count — and still serves correct iCal', function () {
    $user = User::factory()->create();
    [, $url] = feedFor($user);

    $connection = $user->calendarConnection()->first();
    expect($connection->last_used_at)->toBeNull();

    $this->get($url, ['User-Agent' => GOOGLE_UA])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertSee('BEGIN:VCALENDAR', false);

    $connection->refresh();
    expect($connection->last_used_at)->not->toBeNull();
    expect($connection->last_client)->toBe('Google Calendar');
    expect($connection->fetch_count)->toBe(1);
});

it('throttles the stats write: same client within five minutes costs no update', function () {
    $user = User::factory()->create();
    [, $url] = feedFor($user);

    $this->get($url, ['User-Agent' => GOOGLE_UA])->assertOk();
    $connection = $user->calendarConnection()->first();
    $firstStamp = $connection->last_used_at;

    // A fast-polling client: no write inside the window…
    $this->travel(2)->minutes();
    $this->get($url, ['User-Agent' => GOOGLE_UA])->assertOk();
    $connection->refresh();
    expect($connection->fetch_count)->toBe(1);
    expect($connection->last_used_at->equalTo($firstStamp))->toBeTrue();

    // …a new window records again…
    $this->travel(6)->minutes();
    $this->get($url, ['User-Agent' => GOOGLE_UA])->assertOk();
    expect($connection->refresh()->fetch_count)->toBe(2);

    // …and a DIFFERENT client writes immediately (the label matters).
    $this->get($url, ['User-Agent' => APPLE_UA])->assertOk();
    $connection->refresh();
    expect($connection->fetch_count)->toBe(3);
    expect($connection->last_client)->toBe('Apple Calendar');
});

it('parses the big three clients and falls back honestly', function () {
    expect(CalendarFeedService::clientLabel(GOOGLE_UA))->toBe('Google Calendar');
    expect(CalendarFeedService::clientLabel(APPLE_UA))->toBe('Apple Calendar');
    expect(CalendarFeedService::clientLabel('Mozilla/5.0 dataaccessd/1.0'))->toBe('Apple Calendar');
    expect(CalendarFeedService::clientLabel('Microsoft Exchange/15.20 outlook.com'))->toBe('Outlook');
    expect(CalendarFeedService::clientLabel('curl/8.6'))->toBe('a calendar app');
    expect(CalendarFeedService::clientLabel(null))->toBeNull();
});

it('shows never-fetched, then connected with client and relative time', function () {
    $user = User::factory()->create();
    [, $url] = feedFor($user);

    // Fresh link: honest "not yet" + the polling expectation.
    Livewire::actingAs($user)
        ->test('pages::settings.calendar-feed')
        ->assertSee(__('Not connected yet — once you add the link to your calendar app, it\'ll show here.'))
        ->assertSee('Google can take a few hours')
        ->assertSee(__('Check again'));

    // A calendar app fetched: genuine connected state.
    $this->get($url, ['User-Agent' => GOOGLE_UA])->assertOk();

    Livewire::actingAs($user)
        ->test('pages::settings.calendar-feed')
        ->assertSee('Connected — Google Calendar last checked')
        ->assertDontSee(__('Not connected yet — once you add the link to your calendar app, it\'ll show here.'));
});

it('re-reads the status on Check again without pretending to test anything', function () {
    $user = User::factory()->create();
    [, $url] = feedFor($user);

    $component = Livewire::actingAs($user)
        ->test('pages::settings.calendar-feed')
        ->assertSee(__('Not connected yet — once you add the link to your calendar app, it\'ll show here.'));

    $this->get($url, ['User-Agent' => GOOGLE_UA])->assertOk();

    $component->call('refreshStatus')
        ->assertSee('Connected — Google Calendar last checked');
});

it('resets the status on regenerate — a new token is a new, unfetched link', function () {
    $user = User::factory()->create();
    [, $url] = feedFor($user);
    $this->get($url, ['User-Agent' => GOOGLE_UA])->assertOk();
    expect($user->calendarConnection()->first()->fetch_count)->toBe(1);

    Livewire::actingAs($user)
        ->test('pages::settings.calendar-feed')
        ->call('generate')
        ->assertSee(__('Not connected yet — once you add the link to your calendar app, it\'ll show here.'));

    $connection = $user->calendarConnection()->first();
    expect($connection->last_used_at)->toBeNull();
    expect($connection->last_client)->toBeNull();
    expect($connection->fetch_count)->toBe(0);

    // …and the OLD link stopped working.
    $this->get($url)->assertNotFound();
});

it('clears the status on revoke', function () {
    $user = User::factory()->create();
    [, $url] = feedFor($user);
    $this->get($url, ['User-Agent' => GOOGLE_UA])->assertOk();

    app(CalendarFeedService::class)->revoke($user);

    $connection = $user->calendarConnection()->first();
    expect($connection->token_hash)->toBeNull();
    expect($connection->last_used_at)->toBeNull();
    expect($connection->fetch_count)->toBe(0);
    $this->get($url)->assertNotFound();
});

it('keeps the status scoped to its owner', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    [, $aliceUrl] = feedFor($alice);
    feedFor($bob);

    $this->get($aliceUrl, ['User-Agent' => GOOGLE_UA])->assertOk();

    // Bob's page shows HIS (unfetched) status, not Alice's.
    Livewire::actingAs($bob)
        ->test('pages::settings.calendar-feed')
        ->assertSee(__('Not connected yet — once you add the link to your calendar app, it\'ll show here.'));
});

<?php

use App\Models\SalonGhlConnection;
use App\Services\Ghl\GhlApiException;
use App\Services\Ghl\GhlClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/*
| The GoHighLevel v2 API client: headers/auth/location injection, response
| parsing, retry with backoff on 429/5xx, throttling, and safe errors.
| All HTTP is faked — no live calls.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    RateLimiter::clear('ghl-api:loc_1');
});

/**
 * @return array<string, mixed>
 */
function ghlCalendarsPayload(): array
{
    return ['calendars' => [
        [
            'id' => 'cal_1', 'name' => 'Master calendar', 'calendarType' => 'service',
            'isActive' => true, 'locationId' => 'loc_1',
            'teamMembers' => [['userId' => 'ghl_u1', 'priority' => 0.5], ['userId' => 'ghl_u2']],
        ],
        ['id' => 'cal_2', 'name' => 'Consultations', 'locationId' => 'loc_1', 'teamMembers' => []],
    ]];
}

/**
 * @return array<string, mixed>
 */
function ghlUsersPayload(): array
{
    return ['users' => [
        ['id' => 'ghl_u1', 'name' => 'Ana Stylist', 'email' => 'ana@example.com'],
        ['id' => 'ghl_u2', 'firstName' => 'Ben', 'lastName' => 'Barber', 'email' => 'ben@example.com'],
    ]];
}

it('lists calendars with bearer auth, the calendars version header and the location', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(ghlCalendarsPayload())]);

    $calendars = (new GhlClient('pit-token', 'loc_1'))->calendars();

    expect($calendars)->toHaveCount(2);
    expect($calendars[0]->id)->toBe('cal_1');
    expect($calendars[0]->name)->toBe('Master calendar');
    expect($calendars[0]->teamMemberIds)->toBe(['ghl_u1', 'ghl_u2']);
    expect($calendars[1]->teamMemberIds)->toBe([]);

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer pit-token')
        && $request->hasHeader('Version', GhlClient::CALENDARS_VERSION)
        && str_starts_with($request->url(), GhlClient::BASE_URL.'/calendars/')
        && str_contains($request->url(), 'locationId=loc_1'));
});

it('lists users with the users version header and parses name fallbacks', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(ghlUsersPayload())]);

    $users = (new GhlClient('pit-token', 'loc_1'))->users();

    expect($users)->toHaveCount(2);
    expect($users[0]->name)->toBe('Ana Stylist');
    expect($users[1]->name)->toBe('Ben Barber'); // firstName + lastName fallback
    expect($users[1]->email)->toBe('ben@example.com');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Version', GhlClient::USERS_VERSION)
        && str_starts_with($request->url(), GhlClient::BASE_URL.'/users/')
        && str_contains($request->url(), 'locationId=loc_1'));
});

it('retries on 429 with backoff and succeeds', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::sequence()
        ->push(['message' => 'rate limited'], 429)
        ->push(ghlCalendarsPayload())]);

    $calendars = (new GhlClient('pit-token', 'loc_1'))->calendars();

    expect($calendars)->toHaveCount(2);
    Http::assertSentCount(2);
});

it('retries on 5xx and surfaces a safe error when the API stays down', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(['message' => 'boom'], 503)]);

    try {
        (new GhlClient('pit-token', 'loc_1'))->calendars();
        $this->fail('Expected GhlApiException');
    } catch (GhlApiException $e) {
        expect($e->reason)->toBe(GhlApiException::SERVER);
        expect($e->getMessage())->not->toContain('pit-token');
    }

    Http::assertSentCount(3); // initial + 2 retries
});

it('does not retry a rejected token and maps it to unauthorized', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(['message' => 'no'], 401)]);

    try {
        (new GhlClient('pit-token', 'loc_1'))->calendars();
        $this->fail('Expected GhlApiException');
    } catch (GhlApiException $e) {
        expect($e->reason)->toBe(GhlApiException::UNAUTHORIZED);
        expect($e->getMessage())->not->toContain('pit-token');
    }

    Http::assertSentCount(1);
});

it('consumes a per-location throttle slot on every request', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(ghlCalendarsPayload())]);

    $client = new GhlClient('pit-token', 'loc_1');
    $client->calendars();
    $client->calendars();
    $client->calendars();

    // Each request took one slot in the 100-per-10s burst window for THIS
    // location; another location's window is untouched.
    expect(RateLimiter::attempts('ghl-api:loc_1'))->toBe(3);
    expect(RateLimiter::attempts('ghl-api:loc_other'))->toBe(0);
});

it('refuses to build from an unconfigured connection', function () {
    $connection = new SalonGhlConnection;

    expect(fn () => GhlClient::fromConnection($connection))
        ->toThrow(GhlApiException::class);

    Http::assertNothingSent();
});

<?php

use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Support\BookingApiToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;

/*
| The Voice-AI Booking API (Stage 2): per-salon bearer tokens, real slot
| engine availability, booking through the existing CreateBooking path.
| Frozen clock: Mon 2026-06-22 12:00 UTC (08:00 EDT).
*/

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
    Bus::fake([SyncBookingToGhl::class]);
});
afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Salon, 1: User, 2: Service, 3: string} salon, stylist, service, token */
function apiSalon(): array
{
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60); // Monday 9–5
    $service = serviceFor($salon, $stylist, 60);
    $service->update(['name' => 'Haircut']);
    $token = BookingApiToken::generate($salon);

    return [$salon, $stylist, $service, $token];
}

function apiPost(string $routeName, array $payload, ?string $token): TestResponse
{
    return test()->postJson(route($routeName), $payload, $token !== null ? ['Authorization' => "Bearer {$token}"] : []);
}

/** POST with an empty body and everything in the query string — how GHL actually calls. */
function apiQueryPost(string $routeName, string $query, string $token): TestResponse
{
    return test()->post(route($routeName).'?'.$query, [], [
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json',
    ]);
}

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

it('rejects missing, malformed, and wrong tokens with a uniform 401', function () {
    apiSalon();

    apiPost('api.booking.availability', ['service' => 'Haircut'], null)->assertUnauthorized();
    apiPost('api.booking.availability', ['service' => 'Haircut'], 'btsk_1_notarealtoken')->assertUnauthorized();
    apiPost('api.booking.availability', ['service' => 'Haircut'], 'garbage')->assertUnauthorized();
});

it('scopes a token strictly to its own salon', function () {
    [$salonA] = apiSalon();

    // Salon B has a same-named service — and B's token must resolve B's.
    $salonB = bookingSalon();
    $stylistB = stylistWithHours($salonB, 0, 9 * 60, 17 * 60);
    $serviceB = serviceFor($salonB, $stylistB, 30);
    $serviceB->update(['name' => 'Haircut']);
    $tokenB = BookingApiToken::generate($salonB);

    $response = apiPost('api.booking.availability', ['service' => 'Haircut'], $tokenB)->assertOk();
    expect($response->json('service.id'))->toBe($serviceB->id);
    expect($response->json('service.duration_minutes'))->toBe(30); // B's duration, not A's 60

    // A regenerated token invalidates the old one.
    $old = BookingApiToken::generate($salonA);
    $new = BookingApiToken::generate($salonA);
    apiPost('api.booking.availability', ['service' => 'Haircut'], $old)->assertUnauthorized();
    apiPost('api.booking.availability', ['service' => 'Haircut'], $new)->assertOk();
});

// ---------------------------------------------------------------------------
// Availability
// ---------------------------------------------------------------------------

it('returns only genuinely bookable slots with exact per-stylist durations', function () {
    [$salon, $stylist, $service, $token] = apiSalon();

    // This stylist takes 90 minutes for the 60-minute service.
    $service->stylists()->updateExistingPivot($stylist->id, ['duration_override' => 90]);
    // 10:00–11:00 is already booked.
    makeBooking($salon, salonOwnerOf($salon), $stylist, $service, '2026-06-22 10:00');

    $response = apiPost('api.booking.availability', ['service' => 'haircut', 'date' => '2026-06-22'], $token)->assertOk();

    $times = collect($response->json('slots'))->pluck('time');
    expect($response->json('success'))->toBeTrue();
    expect($response->json('service.name'))->toBe('Haircut');
    // The existing booking also runs 90 min (10:00–11:30), so the first
    // bookable start is 11:30; anything whose 90-min block would overlap
    // (9:15 → 10:45) is never offered.
    expect($times)->toContain('11:30 AM');
    expect($times)->not->toContain('11:00 AM');     // inside the existing 90-min booking
    expect($times)->not->toContain('9:15 AM');      // 90-min block would overlap 10:00
    expect($times)->not->toContain('10:00 AM');     // taken
    expect($response->json('slots.0.duration_minutes'))->toBe(90); // the override, not 60
    expect($response->json('slots.0.stylist'))->toBe($stylist->name);
    expect($response->json('message'))->toBeString()->not->toBe('');

    // Cap respected: never more than the configured slots per day.
    expect(count($response->json('slots')))->toBeLessThanOrEqual((int) config('booking_api.max_slots_per_day'));
});

it('merges stylists for "any" and names who can take each slot', function () {
    [$salon, $anna, $service, $token] = apiSalon();
    $ben = stylistWithHours($salon, 0, 13 * 60, 17 * 60); // afternoons only
    $service->stylists()->attach($ben->id, ['salon_id' => $salon->id]);

    // Fill Anna's whole Monday so only Ben's afternoon remains.
    foreach (['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00'] as $time) {
        makeBooking($salon, salonOwnerOf($salon), $anna, $service, "2026-06-22 {$time}");
    }

    $any = apiPost('api.booking.availability', ['service' => 'Haircut', 'stylist' => 'any', 'date' => '2026-06-22'], $token)->assertOk();
    expect(collect($any->json('slots'))->pluck('stylist')->unique()->all())->toBe([$ben->name]);

    // Stylist-specific: Anna has nothing left, and asking for her says so.
    $annaOnly = apiPost('api.booking.availability', ['service' => 'Haircut', 'stylist' => $anna->name, 'date' => '2026-06-22'], $token)->assertOk();
    expect($annaOnly->json('slots'))->toBe([]);
    expect($annaOnly->json('message'))->toContain('no openings');
});

it('explains an unknown service by listing what the salon offers', function () {
    [, , , $token] = apiSalon();

    $response = apiPost('api.booking.availability', ['service' => 'perm'], $token)->assertStatus(422);

    expect($response->json('success'))->toBeFalse();
    expect($response->json('error'))->toBe('unknown_service');
    expect($response->json('services'))->toBe(['Haircut']);
    expect($response->json('message'))->toContain('Haircut'); // speakable, lists options
});

it('returns slot times in the salon timezone as ISO with spoken labels', function () {
    [, , , $token] = apiSalon();

    $response = apiPost('api.booking.availability', ['service' => 'Haircut', 'date' => '2026-06-22'], $token)->assertOk();

    expect($response->json('timezone'))->toBe('America/New_York');
    expect($response->json('slots.0.starts_at'))->toContain('-04:00'); // EDT offset
    expect($response->json('slots.0.spoken'))->toContain('June 22');
});

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

it('books through the real path: correct duration, voice source, GHL push', function () {
    [$salon, $stylist, $service, $token] = apiSalon();

    $response = apiPost('api.booking.create', [
        'service' => 'Haircut',
        'stylist' => $stylist->name,
        'datetime' => '2026-06-22T14:00:00-04:00',
        'client' => ['name' => 'Voice Vera', 'phone' => '+15550100'],
        'notes' => 'Booked over the phone',
    ], $token)->assertCreated();

    expect($response->json('success'))->toBeTrue();
    expect($response->json('confirmation.stylist'))->toBe($stylist->name);
    expect($response->json('message'))->toContain("You're booked for Haircut");

    $booking = $salon->bookings()->with('items')->findOrFail($response->json('booking_id'));
    expect($booking->source)->toBe(BookingSource::VoiceAi);
    expect($booking->booked_by_type)->toBe(BookedByType::VoiceAi);
    expect($booking->booked_by_user_id)->toBeNull();
    expect($booking->status)->toBe(BookingStatus::Booked);
    expect($booking->items->first()->starts_at->toIso8601String())->toBe('2026-06-22T18:00:00+00:00'); // 14:00 EDT
    expect($booking->items->first()->ends_at->diffInMinutes($booking->items->first()->starts_at))->toBe(-60.0);
    expect($booking->client->name)->toBe('Voice Vera');

    Bus::assertDispatched(SyncBookingToGhl::class, 1);
});

it('reuses an existing client by phone and links a supplied GHL contact id', function () {
    [$salon, $stylist, , $token] = apiSalon();
    $existing = Client::factory()->for($salon)->create(['name' => 'Regular Rae', 'phone' => '+15550111', 'ghl_contact_id' => null]);

    apiPost('api.booking.create', [
        'service' => 'Haircut',
        'datetime' => '2026-06-22T15:00:00-04:00',
        'client' => ['name' => 'Rae (from call)', 'phone' => '+15550111'],
        'ghl_contact_id' => 'ghl_c_9',
    ], $token)->assertCreated();

    expect($salon->clients()->count())->toBe(1); // matched, not duplicated
    expect($existing->refresh()->ghl_contact_id)->toBe('ghl_c_9'); // backfilled
    expect($salon->bookings()->first()->client_id)->toBe($existing->id);
});

it('replays an identical request idempotently — no double booking', function () {
    [$salon, , , $token] = apiSalon();

    $payload = [
        'service' => 'Haircut',
        'datetime' => '2026-06-22T14:00:00-04:00',
        'client' => ['name' => 'Retry Rita', 'phone' => '+15550122'],
    ];

    $first = apiPost('api.booking.create', $payload, $token)->assertCreated();
    $second = apiPost('api.booking.create', $payload, $token)->assertCreated();

    expect($second->json('idempotent'))->toBeTrue();
    expect($second->json('booking_id'))->toBe($first->json('booking_id'));
    expect($second->json('message'))->toContain("You're booked");
    expect($salon->bookings()->count())->toBe(1);
});

it('offers alternatives when the requested slot was just taken', function () {
    [$salon, $stylist, $service, $token] = apiSalon();
    makeBooking($salon, salonOwnerOf($salon), $stylist, $service, '2026-06-22 14:00'); // races the caller

    $response = apiPost('api.booking.create', [
        'service' => 'Haircut',
        'stylist' => $stylist->name,
        'datetime' => '2026-06-22T14:00:00-04:00',
        'client' => ['name' => 'Late Lou', 'phone' => '+15550133'],
    ], $token)->assertStatus(409);

    expect($response->json('success'))->toBeFalse();
    expect($response->json('error'))->toBe('slot_unavailable');
    expect(count($response->json('alternatives')))->toBeGreaterThan(0);
    expect(count($response->json('alternatives')))->toBeLessThanOrEqual((int) config('booking_api.alternatives'));
    expect($response->json('alternatives.0.time'))->not->toBe('2:00 PM');
    expect($response->json('message'))->toContain('just taken');

    expect($salon->bookings()->count())->toBe(1); // only the pre-existing one
});

it('assigns a free stylist when none is requested', function () {
    [$salon, $anna, $service, $token] = apiSalon();
    $ben = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service->stylists()->attach($ben->id, ['salon_id' => $salon->id]);
    makeBooking($salon, salonOwnerOf($salon), $anna, $service, '2026-06-22 14:00'); // Anna busy at 2pm

    $response = apiPost('api.booking.create', [
        'service' => 'Haircut',
        'datetime' => '2026-06-22T14:00:00-04:00',
        'client' => ['name' => 'Flexible Fay', 'phone' => '+15550144'],
    ], $token)->assertCreated();

    expect($response->json('confirmation.stylist'))->toBe($ben->name);
});

// ---------------------------------------------------------------------------
// Wire tolerance — the shapes GHL actually sends (query string, empty body,
// double-encoded values, flattened client fields).
// ---------------------------------------------------------------------------

it('reads availability params from the query string with an empty body', function () {
    [, , $service, $token] = apiSalon();
    $service->update(['name' => 'Hair Cut']);

    // Single-encoded on the wire (normal): PHP's own decode yields "Hair Cut".
    $response = apiQueryPost('api.booking.availability', 'service=Hair%20Cut&stylist=any&date=2026-06-22', $token)->assertOk();

    expect($response->json('success'))->toBeTrue();
    expect($response->json('service.name'))->toBe('Hair Cut');
    expect($response->json('slots'))->not->toBe([]);
});

it('decodes a double-encoded service name instead of 422ing', function () {
    [, , $service, $token] = apiSalon();
    $service->update(['name' => 'Hair Cut']);

    // GHL sent Hair%2520Cut: the server receives the literal "Hair%20Cut".
    $wire = apiQueryPost('api.booking.availability', 'service=Hair%2520Cut&stylist=any&date=2026-06-22', $token)->assertOk();
    expect($wire->json('service.name'))->toBe('Hair Cut');
    expect($wire->json('slots'))->not->toBe([]);

    // The same literal arriving in a JSON body decodes too (proves the
    // normalization is ours, not the framework's query parse).
    $body = apiPost('api.booking.availability', ['service' => 'Hair%20Cut', 'date' => '2026-06-22'], $token)->assertOk();
    expect($body->json('service.name'))->toBe('Hair Cut');
});

it('books from a pure query-string request with encoded values and flattened client fields', function () {
    [$salon, $stylist, $service, $token] = apiSalon();
    $service->update(['name' => 'Hair Cut']);

    $response = apiQueryPost(
        'api.booking.create',
        'service=Hair%2520Cut&stylist=any'
            .'&datetime=2026-06-22T14%253A00%253A00-04%253A00' // double-encoded ISO datetime
            .'&client_name=Query%2520Quinn&client_phone=%2B15550188',
        $token,
    )->assertCreated();

    expect($response->json('success'))->toBeTrue();
    expect($response->json('confirmation.service'))->toBe('Hair Cut');

    $booking = $salon->bookings()->with('items', 'client')->findOrFail($response->json('booking_id'));
    expect($booking->client->name)->toBe('Query Quinn');
    expect($booking->client->phone)->toBe('+15550188');
    expect($booking->items->first()->starts_at->toIso8601String())->toBe('2026-06-22T18:00:00+00:00'); // 14:00 EDT
});

it('accepts a nested client in the query string and repairs a "+" offset that became a space', function () {
    [$salon, , $service, $token] = apiSalon();
    $service->update(['name' => 'Hair Cut']);

    // An unencoded "+00:00" offset is parsed as " 00:00" — must be repaired,
    // not mangled into an invalid datetime. 18:00 UTC = 14:00 EDT.
    $response = apiQueryPost(
        'api.booking.create',
        'service=Hair+Cut&datetime=2026-06-22T18:00:00+00:00&client[name]=Nested%20Nia&client[phone]=%2B15550199',
        $token,
    )->assertCreated();

    $booking = $salon->bookings()->with('items', 'client')->findOrFail($response->json('booking_id'));
    expect($booking->client->name)->toBe('Nested Nia');
    expect($booking->items->first()->starts_at->toIso8601String())->toBe('2026-06-22T18:00:00+00:00');
});

it('still explains a genuinely unknown service after decoding', function () {
    [, , $service, $token] = apiSalon();
    $service->update(['name' => 'Hair Cut']);

    $response = apiQueryPost('api.booking.availability', 'service=Perm%2520Wave&date=2026-06-22', $token)->assertStatus(422);

    expect($response->json('error'))->toBe('unknown_service');
    expect($response->json('services'))->toBe(['Hair Cut']);
    expect($response->json('message'))->toContain('Perm Wave'); // the decoded term, not the encoded blob
});

it('never over-decodes legitimate values containing a bare percent sign', function () {
    [, , $service, $token] = apiSalon();
    $service->update(['name' => '100% natural glow']);

    $response = apiPost('api.booking.availability', ['service' => '100% natural glow', 'date' => '2026-06-22'], $token)->assertOk();

    expect($response->json('service.name'))->toBe('100% natural glow');
});

it('refuses cross-salon references cleanly', function () {
    [, , , $tokenA] = apiSalon();

    $salonB = bookingSalon();
    $stylistB = stylistWithHours($salonB, 0, 9 * 60, 17 * 60);
    $serviceB = serviceFor($salonB, $stylistB, 30);
    $serviceB->update(['name' => 'Beard trim']);

    // Salon A's token cannot see salon B's service — helpful 422, not a leak.
    $response = apiPost('api.booking.create', [
        'service' => 'Beard trim',
        'datetime' => '2026-06-22T14:00:00-04:00',
        'client' => ['name' => 'Wrong Door'],
    ], $tokenA)->assertStatus(422);

    expect($response->json('error'))->toBe('unknown_service');
    expect($response->json('services'))->not->toContain('Beard trim');
    expect($salonB->bookings()->count())->toBe(0);
});

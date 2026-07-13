<?php

use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Jobs\SyncBookingToGhl;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\Service;
use App\Models\User;
use App\Support\WidgetBranding;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/*
| The embeddable public booking widget: slug-scoped public endpoints over the
| shared engine, framing headers scoped to the widget page only, and the
| honeypot + page-token bot gate. Frozen clock: Mon 2026-06-22 12:00 UTC.
*/

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
    Bus::fake([SyncBookingToGhl::class]);
});
afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Salon, 1: User, 2: Service} */
function widgetSalon(): array
{
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60); // Monday 9–5
    $service = serviceFor($salon, $stylist, 60);
    $service->update(['name' => 'Haircut', 'price_cents' => 4500]);

    return [$salon, $stylist, $service];
}

/** A page token like the widget page embeds, backdated $ageSeconds. */
function widgetToken(Salon $salon, int $ageSeconds = 30): string
{
    return Crypt::encryptString((string) json_encode([
        'salon' => $salon->id,
        'iat' => now()->timestamp - $ageSeconds,
    ]));
}

/** A valid book payload for the salon's Haircut at 2 PM. */
function widgetPayload(Salon $salon, array $overrides = []): array
{
    return array_merge([
        'service' => $salon->services()->firstOrFail()->id,
        'stylist' => 'any',
        'date' => '2026-06-22',
        'time' => '2:00 PM',
        'client' => ['name' => 'Widget Wendy', 'phone' => '+15550301'],
        'token' => widgetToken($salon),
        'website' => '',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Widget page + framing headers
// ---------------------------------------------------------------------------

it('renders the widget page publicly and allows framing for it alone', function () {
    [$salon] = widgetSalon();

    $response = $this->get(route('salon.widget', $salon))
        ->assertOk()
        ->assertSee($salon->name)
        ->assertSee('Haircut')
        ->assertHeaderMissing('X-Frame-Options');

    expect($response->headers->get('Content-Security-Policy'))->toContain('frame-ancestors *');

    // The rest of the app stays clickjacking-protected.
    $login = $this->get(route('login'));
    expect($login->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
    expect($login->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");
});

it('404s the widget for an unknown or inactive salon', function () {
    [$salon] = widgetSalon();
    $salon->update(['active' => false]);

    $this->get(route('salon.widget', $salon))->assertNotFound();
    $this->getJson(route('salon.widget.services', $salon))->assertNotFound();
});

it('embeds only public data and a bot token in the page', function () {
    [$salon] = widgetSalon();
    Client::factory()->for($salon)->create(['name' => 'Secret Sally']);

    $this->get(route('salon.widget', $salon))
        ->assertOk()
        ->assertDontSee('Secret Sally');
});

// ---------------------------------------------------------------------------
// Public API: services + availability
// ---------------------------------------------------------------------------

it('lists only the salon own bookable services with public fields', function () {
    [$salonA, , $serviceA] = widgetSalon();
    [$salonB] = widgetSalon();
    $salonB->services()->first()->update(['name' => 'Beard trim']);

    // A service with no qualified stylist is unbookable → hidden.
    Service::factory()->for($salonA)->create(['name' => 'Orphan service', 'active' => true]);

    $response = $this->getJson(route('salon.widget.services', $salonA))->assertOk();

    expect($response->json('salon'))->toBe(['name' => $salonA->name, 'timezone' => $salonA->timezone]);
    expect(collect($response->json('services'))->pluck('name')->all())->toBe(['Haircut']);
    expect($response->json('services.0.price'))->toBe('$45');
    expect(array_keys($response->json('services.0')))->toBe(['id', 'name', 'duration_minutes', 'price', 'price_cents', 'stylists']);
    expect(array_keys($response->json('services.0.stylists.0')))->toBe(['id', 'name']);
    expect($response->content())->not->toContain('Beard trim');
});

it('returns availability with exact per-stylist durations', function () {
    [$salon, $stylist, $service] = widgetSalon();
    $service->stylists()->updateExistingPivot($stylist->id, ['duration_override' => 90]);
    makeBooking($salon, salonOwnerOf($salon), $stylist, $service, '2026-06-22 10:00');

    $response = $this->getJson(route('salon.widget.availability', $salon).'?service='.$service->id.'&stylist=any&date=2026-06-22')
        ->assertOk();

    $times = collect($response->json('slots'))->pluck('time');
    expect($times)->toContain('11:30 AM');   // after the 90-min booking block
    expect($times)->not->toContain('9:15 AM'); // would overlap the 10:00 booking
    expect($response->json('slots.0.duration_minutes'))->toBe(90);
});

// ---------------------------------------------------------------------------
// Public API: book
// ---------------------------------------------------------------------------

it('books through the shared engine with source web_widget and pushes to GHL', function () {
    [$salon, $stylist] = widgetSalon();

    $response = $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon))
        ->assertCreated();

    expect($response->json('success'))->toBeTrue();
    expect($response->json('confirmation.stylist'))->toBe($stylist->name);

    $booking = $salon->bookings()->with('client')->findOrFail($response->json('booking_id'));
    expect($booking->source)->toBe(BookingSource::WebWidget);
    expect($booking->booked_by_type)->toBe(BookedByType::WebWidget);
    expect($booking->booked_by_user_id)->toBeNull();
    expect($booking->client->name)->toBe('Widget Wendy');

    Bus::assertDispatched(SyncBookingToGhl::class, 1);
});

it('re-validates at submit and offers alternatives on a race', function () {
    [$salon, $stylist, $service] = widgetSalon();
    makeBooking($salon, salonOwnerOf($salon), $stylist, $service, '2026-06-22 14:00');

    $response = $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon))
        ->assertStatus(409);

    expect($response->json('error'))->toBe('slot_unavailable');
    expect(count($response->json('alternatives')))->toBeGreaterThan(0);
    expect($salon->bookings()->count())->toBe(1);
});

it('is tenant-scoped: salon A cannot book salon B services or use B tokens', function () {
    [$salonA] = widgetSalon();
    [$salonB, , $serviceB] = widgetSalon();

    // B's service id through A's slug → unknown service, nothing booked.
    $this->postJson(route('salon.widget.book', $salonA), widgetPayload($salonA, ['service' => $serviceB->id]))
        ->assertStatus(422);
    expect($salonA->bookings()->count())->toBe(0);
    expect($salonB->bookings()->count())->toBe(0);

    // A token issued for B fails A's bot gate.
    $this->postJson(route('salon.widget.book', $salonA), widgetPayload($salonA, ['token' => widgetToken($salonB)]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'rejected');
});

// ---------------------------------------------------------------------------
// Bot gate + rate limiting
// ---------------------------------------------------------------------------

it('rejects bot-shaped submissions: honeypot, instant, stale and garbled tokens', function () {
    [$salon] = widgetSalon();

    // Honeypot filled.
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, ['website' => 'http://spam.example']))
        ->assertStatus(422)->assertJsonPath('error', 'rejected');

    // Submitted faster than a human can read the form.
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, ['token' => widgetToken($salon, 0)]))
        ->assertStatus(422)->assertJsonPath('error', 'rejected');

    // Stale token (past the TTL).
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, ['token' => widgetToken($salon, 13 * 3600)]))
        ->assertStatus(422)->assertJsonPath('error', 'rejected');

    // Garbled token.
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, ['token' => 'not-a-token']))
        ->assertStatus(422)->assertJsonPath('error', 'rejected');

    expect($salon->bookings()->count())->toBe(0);
});

it('rate-limits the widget endpoints per IP and salon', function () {
    [$salon] = widgetSalon();
    config(['booking_api.widget_rate_limit' => 2]);

    $this->getJson(route('salon.widget.services', $salon))->assertOk();
    $this->getJson(route('salon.widget.services', $salon))->assertOk();
    $this->getJson(route('salon.widget.services', $salon))->assertStatus(429);
});

// ---------------------------------------------------------------------------
// Loader script + settings snippet
// ---------------------------------------------------------------------------

it('serves the loader script with the resize handler', function () {
    $response = $this->get(route('widget.script'))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('javascript');
    expect($response->content())
        ->toContain('data-bookthestyle-salon')
        ->toContain('bts:resize')
        ->toContain('iframe');
});

it('shows the embed snippets with the salon slug in settings', function () {
    [$salon] = widgetSalon();
    $owner = User::factory()->create();
    SalonMembership::factory()->for($owner)->for($salon)->owner()->create();
    $this->actingAs($owner);

    Livewire\Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->assertSee('data-bookthestyle-salon="'.$salon->slug.'"')
        ->assertSee(route('widget.script'))
        ->assertSee(route('salon.widget', $salon));
});

// ---------------------------------------------------------------------------
// Multi-service visits (Vitrine × Duet widget)
// ---------------------------------------------------------------------------

it('computes availability for the FULL multi-service visit — no under-booking', function () {
    [$salon, $stylist] = widgetSalon(); // Haircut, 60 min, Mon 9–5
    $colour = serviceFor($salon, $stylist, 120);
    $colour->update(['name' => 'Full colour', 'price_cents' => 9500]);

    // A 60-min blocker at 14:00 leaves free stretches 9–14 and 15–17.
    makeBooking($salon, salonOwnerOf($salon), $stylist, $salon->services()->where('name', 'Haircut')->firstOrFail(), '2026-06-22 14:00', 'Blocker');

    config(['booking_api.max_slots_per_day' => 50]);

    $ids = $salon->services()->pluck('id')->all();
    $query = http_build_query(['stylist' => 'any', 'date' => '2026-06-22']);
    foreach ($ids as $id) {
        $query .= '&services[]='.$id;
    }

    $times = collect($this->getJson(route('salon.widget.availability', ['salon' => $salon]).'?'.$query)
        ->assertOk()
        ->json('slots'))->pluck('time');

    // The 3-hour visit fits only when it ENDS by 14:00 (9:00–11:00 starts).
    expect($times)->toContain('9:00 AM');
    expect($times)->toContain('11:00 AM');
    // A single service would fit at these — the whole visit does not.
    expect($times)->not->toContain('12:00 PM');
    expect($times)->not->toContain('1:00 PM');
    expect($times)->not->toContain('3:00 PM'); // would run past close

    // The offered duration is the combined visit length.
    $response = $this->getJson(route('salon.widget.availability', ['salon' => $salon]).'?'.$query)->json();
    expect($response['slots'][0]['duration_minutes'])->toBe(180);
});

it('books a multi-service visit through the engine: back-to-back items, one visit group, GHL push', function () {
    [$salon, $stylist, $haircut] = widgetSalon();
    $colour = serviceFor($salon, $stylist, 120);
    $colour->update(['name' => 'Full colour']);

    $payload = widgetPayload($salon, ['services' => [$haircut->id, $colour->id], 'time' => '9:00 AM']);
    unset($payload['service']);

    $this->postJson(route('salon.widget.book', ['salon' => $salon]), $payload)->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('confirmation.services', ['Haircut', 'Full colour']);

    // One visit: a booking per service, linked by the visit group, laid
    // back-to-back with the same stylist.
    $bookings = $salon->bookings()->with('items')->get();
    expect($bookings)->toHaveCount(2);
    expect($bookings->pluck('visit_group_id')->unique())->toHaveCount(1);
    expect($bookings->pluck('visit_group_id')->first())->not->toBeNull();

    $items = $bookings->flatMap->items->sortBy('starts_at')->values();
    expect($items[0]->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('09:00');
    expect($items[1]->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('10:00');
    expect($items->pluck('stylist_id')->unique()->all())->toBe([$stylist->id]);

    expect($bookings->first()->source)->toBe(BookingSource::WebWidget);
    Bus::assertDispatched(SyncBookingToGhl::class, 2);
});

it('refuses a multi-service slot that only fits the first service', function () {
    [$salon, $stylist, $haircut] = widgetSalon();
    $colour = serviceFor($salon, $stylist, 120);

    // 3:00 PM: the 60-min haircut fits, the 3-hour visit runs past 5 PM close.
    $payload = widgetPayload($salon, ['services' => [$haircut->id, $colour->id], 'time' => '3:00 PM']);
    unset($payload['service']);

    $this->postJson(route('salon.widget.book', ['salon' => $salon]), $payload)->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', 'slot_unavailable');

    expect($salon->bookings()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Multi-stylist arrangements (auto fallback + manual per-service)
// ---------------------------------------------------------------------------

it('auto-arranges a back-to-back multi-stylist visit when no single stylist performs all services', function () {
    [$salon, $stylist, $haircut] = widgetSalon(); // A: Haircut 60, Mon 9–5
    $other = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $colour = serviceFor($salon, $other, 30); // only stylist B performs it
    $colour->update(['name' => 'Gloss']);

    // Availability composes the team arrangement instead of refusing.
    $query = 'services[]='.$haircut->id.'&services[]='.$colour->id.'&stylist=any&date=2026-06-22';
    $slots = $this->getJson(route('salon.widget.availability', ['salon' => $salon]).'?'.$query)
        ->assertOk()
        ->json('slots');

    $nine = collect($slots)->firstWhere('time', '9:00 AM');
    expect($nine)->not->toBeNull();
    expect($nine['multi_stylist'])->toBeTrue();
    expect($nine['duration_minutes'])->toBe(90);
    expect($nine['stylists'][0]['stylist_id'])->toBe($stylist->id);
    expect($nine['stylists'][1]['stylist_id'])->toBe($other->id);
    expect($nine['stylists'][1]['time'])->toBe('10:00 AM'); // back-to-back
    expect($nine['stylist'])->toBe($stylist->name.' + '.$other->name);

    // Booking that slot creates one visit: a booking per service, each with
    // ITS OWN stylist, linked by the visit group, one GHL push per booking.
    $payload = widgetPayload($salon, [
        'services' => [$haircut->id, $colour->id],
        'stylists' => [(string) $stylist->id, (string) $other->id],
        'time' => '9:00 AM',
    ]);
    unset($payload['service'], $payload['stylist']);

    $response = $this->postJson(route('salon.widget.book', ['salon' => $salon]), $payload)->assertCreated();
    expect($response->json('confirmation.multi_stylist'))->toBeTrue();
    expect($response->json('message'))->toContain('then');

    $items = $salon->bookings()->with('items')->get()->flatMap->items->sortBy('starts_at')->values();
    expect($salon->bookings()->pluck('visit_group_id')->unique())->toHaveCount(1);
    expect($items->pluck('stylist_id')->all())->toBe([$stylist->id, $other->id]);
    expect($items[1]->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('10:00');
    Bus::assertDispatched(SyncBookingToGhl::class, 2);
});

it('falls back to a team arrangement only on days the shared stylist cannot host the visit', function () {
    [$salon, $shared, $haircut] = widgetSalon(); // shared stylist does Haircut
    $colour = serviceFor($salon, $shared, 120);  // ...and Full colour
    $helperA = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $helperB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $haircut->stylists()->attach($helperA->id, ['salon_id' => $salon->id]);
    $colour->stylists()->attach($helperB->id, ['salon_id' => $salon->id]);

    // Fully book the shared stylist on the 22nd; the helpers stay free.
    foreach (range(9, 16) as $hour) {
        makeBooking($salon, salonOwnerOf($salon), $shared, $haircut, sprintf('2026-06-22 %02d:00', $hour), 'Blocker');
    }

    $query = 'services[]='.$haircut->id.'&services[]='.$colour->id.'&stylist=any';

    $slots = $this->getJson(route('salon.widget.availability', ['salon' => $salon]).'?'.$query.'&date=2026-06-22')
        ->assertOk()->json('slots');
    expect($slots)->not->toBeEmpty();
    expect(collect($slots)->every(fn (array $s): bool => $s['multi_stylist'] === true))->toBeTrue();

    // A week later the shared stylist is free: one stylist takes the visit.
    $nextWeek = $this->getJson(route('salon.widget.availability', ['salon' => $salon]).'?'.$query.'&date=2026-06-29')
        ->assertOk()->json('slots');
    expect(collect($nextWeek)->every(fn (array $s): bool => $s['multi_stylist'] === false))->toBeTrue();

    // The month calendar counts the arrangement-only day as available.
    $dates = $this->getJson(route('salon.widget.month', ['salon' => $salon]).'?'.$query.'&month=2026-06')
        ->assertOk()->json('dates');
    expect($dates)->toContain('2026-06-22');
});

it('computes availability for a MANUAL per-service assignment and books exactly that arrangement', function () {
    [$salon, $stylistA, $haircut] = widgetSalon();
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $colour = serviceFor($salon, $stylistB, 120);
    $colour->update(['name' => 'Full colour']);
    $haircut->stylists()->attach($stylistB->id, ['salon_id' => $salon->id]);
    $colour->stylists()->attach($stylistA->id, ['salon_id' => $salon->id]);

    // B is busy 10–11, so "A cuts, then B colours" cannot start at 9:00
    // (B's leg would run 10:00–12:00) — but can start at 10:00.
    makeBooking($salon, salonOwnerOf($salon), $stylistB, $haircut, '2026-06-22 10:00', 'Blocker');

    $query = 'services[]='.$haircut->id.'&services[]='.$colour->id
        .'&stylists[]='.$stylistA->id.'&stylists[]='.$stylistB->id;

    $times = collect($this->getJson(route('salon.widget.availability', ['salon' => $salon]).'?'.$query.'&date=2026-06-22')
        ->assertOk()->json('slots'))->pluck('time');
    expect($times)->not->toContain('9:00 AM');
    expect($times)->toContain('10:00 AM');

    $payload = widgetPayload($salon, [
        'services' => [$haircut->id, $colour->id],
        'stylists' => [(string) $stylistA->id, (string) $stylistB->id],
        'time' => '10:00 AM',
    ]);
    unset($payload['service'], $payload['stylist']);

    $this->postJson(route('salon.widget.book', ['salon' => $salon]), $payload)->assertCreated();

    $items = $salon->bookings()->whereNotNull('visit_group_id')->with('items')->get()->flatMap->items->sortBy('starts_at')->values();
    expect($items->pluck('stylist_id')->all())->toBe([$stylistA->id, $stylistB->id]);
    expect($items[0]->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('10:00');
    expect($items[1]->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('11:00');
});

it('rejects a manual assignment of a stylist who does not perform that service', function () {
    [$salon, $stylistA, $haircut] = widgetSalon();
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    serviceFor($salon, $stylistB, 30); // B exists but does NOT perform Haircut

    $query = 'services[]='.$haircut->id.'&stylists[]='.$stylistB->id.'&date=2026-06-22';

    $this->getJson(route('salon.widget.availability', ['salon' => $salon]).'?'.$query)
        ->assertStatus(422)
        ->assertJsonPath('error', 'unknown_stylist');
});

// ---------------------------------------------------------------------------
// Itineraries: the per-service loop (independently timed appointments)
// ---------------------------------------------------------------------------

/** A valid items-shaped book payload (per-service loop finalize). */
function itineraryPayload(Salon $salon, array $items): array
{
    return [
        'items' => $items,
        'client' => ['name' => 'Loop Lucy', 'phone' => '+15550302'],
        'token' => widgetToken($salon),
        'website' => '',
    ];
}

it('books independently timed appointments — own stylist and own start each, gaps allowed', function () {
    [$salon, $stylistA, $haircut] = widgetSalon();
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $colour = serviceFor($salon, $stylistB, 120);
    $colour->update(['name' => 'Full colour']);

    // Haircut with A at 10, colour with B at 2 — a 3-hour gap, NOT back-to-back.
    $payload = itineraryPayload($salon, [
        ['service' => $haircut->id, 'stylist' => (string) $stylistA->id, 'date' => '2026-06-22', 'time' => '10:00 AM'],
        ['service' => $colour->id, 'stylist' => (string) $stylistB->id, 'date' => '2026-06-22', 'time' => '2:00 PM'],
    ]);

    $response = $this->postJson(route('salon.widget.book', ['salon' => $salon]), $payload)->assertCreated();
    expect($response->json('confirmation.services'))->toBe(['Haircut', 'Full colour']);
    expect($response->json('message'))->toContain('10:00 AM')->toContain('2:00 PM');

    $bookings = $salon->bookings()->with('items')->get();
    expect($bookings)->toHaveCount(2);
    expect($bookings->pluck('visit_group_id')->unique())->toHaveCount(1);
    expect($bookings->pluck('visit_group_id')->first())->not->toBeNull();

    $items = $bookings->flatMap->items->sortBy('starts_at')->values();
    expect($items[0]->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('10:00');
    expect($items[1]->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('14:00');
    expect($items->pluck('stylist_id')->all())->toBe([$stylistA->id, $stylistB->id]);
    Bus::assertDispatched(SyncBookingToGhl::class, 2);

    // Idempotent replay: the same visit again returns the same booking.
    $this->postJson(route('salon.widget.book', ['salon' => $salon]), $payload)
        ->assertCreated()
        ->assertJsonPath('idempotent', true);
    expect($salon->bookings()->count())->toBe(2);
});

it('rejects the same stylist double-booked across overlapping items, naming the service', function () {
    [$salon, $stylist, $haircut] = widgetSalon();
    $colour = serviceFor($salon, $stylist, 120);
    $colour->update(['name' => 'Full colour']);

    // Maya cuts 10:00–11:00; a 10:30 colour with Maya overlaps her own visit.
    $response = $this->postJson(route('salon.widget.book', ['salon' => $salon]), itineraryPayload($salon, [
        ['service' => $haircut->id, 'stylist' => (string) $stylist->id, 'date' => '2026-06-22', 'time' => '10:00 AM'],
        ['service' => $colour->id, 'stylist' => (string) $stylist->id, 'date' => '2026-06-22', 'time' => '10:30 AM'],
    ]))->assertStatus(409);

    expect($response->json('error'))->toBe('slot_unavailable');
    expect($response->json('conflicts.0.index'))->toBe(1);
    expect($response->json('conflicts.0.service'))->toBe('Full colour');
    expect($salon->bookings()->count())->toBe(0);
});

it('re-validates every item at finalize and flags exactly the one that lost its slot', function () {
    [$salon, $stylistA, $haircut] = widgetSalon();
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $colour = serviceFor($salon, $stylistB, 120);
    $colour->update(['name' => 'Full colour']);

    // Someone books B at 2 PM between slot display and finalize.
    makeBooking($salon, salonOwnerOf($salon), $stylistB, $colour, '2026-06-22 14:00', 'Race winner');

    $response = $this->postJson(route('salon.widget.book', ['salon' => $salon]), itineraryPayload($salon, [
        ['service' => $haircut->id, 'stylist' => (string) $stylistA->id, 'date' => '2026-06-22', 'time' => '10:00 AM'],
        ['service' => $colour->id, 'stylist' => (string) $stylistB->id, 'date' => '2026-06-22', 'time' => '2:00 PM'],
    ]))->assertStatus(409);

    expect($response->json('conflicts'))->toHaveCount(1);
    expect($response->json('conflicts.0.index'))->toBe(1);
    expect($response->json('conflicts.0.service'))->toBe('Full colour');
    expect($response->json('message'))->toContain('Full colour');
    // Nothing partial: the haircut was NOT booked either.
    expect($salon->bookings()->where('client_id', '!=', null)->whereHas('client', fn ($q) => $q->where('name', 'Loop Lucy'))->count())->toBe(0);
});

it('resolves "any" per item around the visit\'s own other items', function () {
    [$salon, $stylistA, $haircut] = widgetSalon();
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $haircut->stylists()->attach($stylistB->id, ['salon_id' => $salon->id]);

    // Two haircuts at the SAME time with "any": two different stylists.
    $this->postJson(route('salon.widget.book', ['salon' => $salon]), itineraryPayload($salon, [
        ['service' => $haircut->id, 'stylist' => 'any', 'date' => '2026-06-22', 'time' => '10:00 AM'],
        ['service' => $haircut->id, 'stylist' => 'any', 'date' => '2026-06-22', 'time' => '10:00 AM'],
    ]))->assertCreated();

    $items = $salon->bookings()->with('items')->get()->flatMap->items;
    expect($items)->toHaveCount(2);
    expect($items->pluck('stylist_id')->sort()->values()->all())->toBe(collect([$stylistA->id, $stylistB->id])->sort()->values()->all());
    expect($items->pluck('starts_at')->unique())->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Inline availability calendar + month endpoint
// ---------------------------------------------------------------------------

it('renders the solid branded split container with the inline calendar — no native date input', function () {
    [$salon] = widgetSalon();

    $response = $this->get(route('salon.widget', $salon))->assertOk();

    // The OS date field is gone; our always-visible calendar replaces it.
    expect($response->getContent())->not->toContain('type="date"');
    $response
        // One rounded shell filled with the SOLID branded surface, split into
        // the info pane (logo/summary/stylist) and the scheduling pane.
        ->assertSee('class="wb-shell"', false)
        ->assertSee('background: var(--wb-surface)', false)
        ->assertSee('border-radius: 24px', false)
        ->assertSee('class="wb-info"', false)
        ->assertSee('class="wb-book"', false)
        ->assertSee('border-right: 1px solid var(--wb-line)', false)  // desktop divider
        ->assertSee('border-bottom: 1px solid var(--wb-line)', false) // stacked divider
        ->assertSee('Select date &amp; time', false)
        // The calendar: 7-column grid, accent-filled selected day, accent-
        // tinted available days, accent-bordered time-slot buttons.
        ->assertSee('bts-cal-grid', false)
        ->assertSee('grid-template-columns: repeat(7, minmax(0, 1fr))', false)
        ->assertSee("wb-day[aria-pressed='true'] { background: var(--accent)", false)
        ->assertSee('color-mix(in srgb, var(--accent) 16%, transparent)', false)
        ->assertSee('color-mix(in srgb, var(--accent) 70%, transparent)', false)
        ->assertSee('api\/widget\/month', false)
        // The calendar window: today through the booking horizon (salon tz).
        ->assertSee('"2026-06-22"', false)
        // Times are labelled with the salon's timezone.
        ->assertSee('Times shown in', false)
        // The per-service loop: add-another + finalize live on the right;
        // the left pane carries the live, removable visit summary.
        ->assertSee('Finalize booking', false)
        ->assertSee('Add another service', false)
        ->assertSee('bts-item-lines', false)
        ->assertSee('wb-remove', false)
        ->assertSee('Your visit', false);
});

it('derives a readable foreground for whatever background the brand sets', function () {
    [$salon] = widgetSalon();

    // Deep navy brand background → the light-on-dark family.
    $salon->update(['branding' => ['surface' => '#1F2A44']]);
    $this->get(route('salon.widget', $salon))
        ->assertOk()
        ->assertSee('--wb-surface: #1F2A44', false)
        ->assertSee('--wb-ink: #FFFFFF', false);

    // Warm paper (the default family) → dark-on-light.
    $salon->update(['branding' => null]);
    $this->get(route('salon.widget', $salon))
        ->assertOk()
        ->assertSee('--wb-ink: #1C1B1A', false)
        // White text on the plum accent for filled states.
        ->assertSee('--wb-accent-ink: #FFFFFF', false);
});

it('reports which dates of a month fit the WHOLE visit — past, closed and full days excluded', function () {
    [$salon, $stylist, $haircut] = widgetSalon(); // Haircut 60 min, Monday 9–5 only
    $colour = serviceFor($salon, $stylist, 120);

    // Fully book Monday the 29th (9–5): no visit of any length fits.
    foreach (range(9, 16) as $hour) {
        makeBooking($salon, salonOwnerOf($salon), $stylist, $haircut, sprintf('2026-06-29 %02d:00', $hour), 'Blocker');
    }

    $query = 'services[]='.$haircut->id.'&services[]='.$colour->id.'&stylist=any&month=2026-06';
    $dates = $this->getJson(route('salon.widget.month', ['salon' => $salon]).'?'.$query)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('dates');

    expect($dates)->toContain('2026-06-22');        // today still has room
    expect($dates)->not->toContain('2026-06-29');   // fully booked
    expect($dates)->not->toContain('2026-06-15');   // past Monday
    expect($dates)->not->toContain('2026-06-23');   // Tuesday — no working hours
});

it('excludes a date where the multi-service visit cannot fit even though one service could', function () {
    [$salon, $stylist, $haircut] = widgetSalon();
    $colour = serviceFor($salon, $stylist, 120);

    // Monday 6 July: booked solid from 10:00 — only a 60-min stretch (9–10) left.
    foreach (range(10, 16) as $hour) {
        makeBooking($salon, salonOwnerOf($salon), $stylist, $haircut, sprintf('2026-07-06 %02d:00', $hour), 'Blocker');
    }

    $base = route('salon.widget.month', ['salon' => $salon]);

    $solo = $this->getJson($base.'?services[]='.$haircut->id.'&stylist=any&month=2026-07')->assertOk()->json('dates');
    expect($solo)->toContain('2026-07-06'); // the haircut alone fits at 9:00

    $combo = $this->getJson($base.'?services[]='.$haircut->id.'&services[]='.$colour->id.'&stylist=any&month=2026-07')
        ->assertOk()->json('dates');
    expect($combo)->not->toContain('2026-07-06'); // the 3-hour visit does not
});

it('validates the month parameter and returns no dates for months outside the window', function () {
    [$salon, , $haircut] = widgetSalon();
    $base = route('salon.widget.month', ['salon' => $salon]);

    $this->getJson($base.'?services[]='.$haircut->id.'&month=junk')
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_request');

    // An all-past month is clamped away server-side: empty, never an error.
    $this->getJson($base.'?services[]='.$haircut->id.'&month=2026-01')
        ->assertOk()
        ->assertJsonPath('dates', []);
});

// ---------------------------------------------------------------------------
// Branding on the widget page
// ---------------------------------------------------------------------------

it('themes the widget page from the salon branding by slug', function () {
    [$salon] = widgetSalon();
    Storage::fake('public');
    $path = UploadedFile::fake()->image('logo.png', 300, 100)->store('branding/'.$salon->id, 'public');

    $salon->update(['branding' => [
        'accent' => '#2F5D7C',
        'secondary' => '#C2A15A',
        'surface' => '#F2EFE9',
        'font' => 'modern',
        'logo_path' => $path,
    ]]);

    $this->get(route('salon.widget', $salon))
        ->assertOk()
        ->assertSee('--accent: #2F5D7C', false)
        ->assertSee('--wb-secondary: #C2A15A', false)
        ->assertSee('--wb-surface: #F2EFE9', false)
        ->assertSee("--wb-display: 'Schibsted Grotesk'", false)
        ->assertSee('/storage/'.$path, false);
});

it('renders the widget with sensible defaults when no branding is set', function () {
    [$salon] = widgetSalon();

    $this->get(route('salon.widget', $salon))
        ->assertOk()
        ->assertSee('--wb-secondary: '.WidgetBranding::DEFAULT_SECONDARY, false)
        ->assertSee('--wb-surface: '.WidgetBranding::DEFAULT_SURFACE, false)
        ->assertSee("--wb-display: 'Fraunces'", false)
        ->assertSee('wb-shell', false)  // one branded rounded container
        ->assertSee('wb-info', false);  // split info pane
});

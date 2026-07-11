<?php

use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Jobs\SyncBookingToGhl;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;

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
    expect(array_keys($response->json('services.0')))->toBe(['id', 'name', 'duration_minutes', 'price', 'stylists']);
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

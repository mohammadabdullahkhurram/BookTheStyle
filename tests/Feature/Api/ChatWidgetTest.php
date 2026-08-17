<?php

use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Widget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

/*
| The conversational chat widget: a bottom-corner bubble (chat.js, app host)
| whose panel iframe loads the guided scripted flow (salon.chat, slug host)
| and books through the SAME public endpoints and shared engine as the
| booking widget — tagged surface=chat → source chat_widget. Guardrails are
| inherited: throttle:widget-api, the honeypot + timestamped-token bot gate,
| slug tenant scoping, public-catalogue-only data. Frozen clock: Mon
| 2026-06-22 12:00 UTC. (widgetSalon / widgetToken / widgetPayload live in
| WidgetBookingTest — same directory, shared Pest helpers.)
*/

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
    Bus::fake([SyncBookingToGhl::class]);
});
afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------------------
// The panel page + the bubble loader
// ---------------------------------------------------------------------------

it('renders the chat panel with the guided flow, catalogue, token — framable by external sites', function () {
    [$salon] = widgetSalon();

    $response = $this->get(route('salon.chat', $salon))
        ->assertOk()
        ->assertSee($salon->name)
        ->assertSee('Haircut')
        ->assertSee(__('Book an appointment'))
        ->assertSee(__('Confirm booking')) // the explicit-confirm step ships in the flow
        ->assertSee('surface') // posts surface=chat to the shared book endpoint
        ->assertHeaderMissing('X-Frame-Options');

    expect($response->headers->get('Content-Security-Policy'))->toContain('frame-ancestors *');

    // Public data only: a real token, no client/booking internals.
    $response->assertSee('cw-stream')->assertDontSee('failed_jobs');
});

it('404s the chat panel for unknown, inactive, and demo salons', function () {
    [$salon] = widgetSalon();

    $salon->update(['active' => false]);
    $this->get(route('salon.chat', $salon))->assertNotFound();
    $salon->update(['active' => true]);

    $demo = demoShowcase();
    $this->get(route('salon.chat', ['salon' => $demo->slug]))->assertNotFound();

    $this->get('http://nope.'.config('app.domain').'/chat-widget')->assertNotFound();
});

it('serves the bubble loader from the app host — defensive, bubble + panel + close wiring', function () {
    $response = $this->get(route('chat.script'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

    $js = $response->getContent();
    expect($js)->toContain('data-bookthestyle-chat')   // the per-salon key attribute
        ->toContain('/chat-widget')                     // panel iframe target
        ->toContain('bts:chat:close')                   // in-panel × closes the bubble
        ->toContain('position:fixed;bottom:20px;right:20px') // bottom-corner bubble
        ->toContain('never break the host page')
        ->not->toContain('@json');                      // fully compiled, no Blade artifacts
});

it('scopes the chat panel to its own salon — another salon\'s catalogue never leaks in', function () {
    [$salonA] = widgetSalon();
    [$salonB, , $serviceB] = widgetSalon();
    $serviceB->update(['name' => 'Secret Balayage Of Salon B']);

    $this->get(route('salon.chat', $salonA))
        ->assertOk()
        ->assertSee('Haircut')
        ->assertDontSee('Secret Balayage Of Salon B');
});

it('opens a specific chat widget by public id — a foreign salon\'s id 404s (no IDOR)', function () {
    [$salonA] = widgetSalon();
    [$salonB] = widgetSalon();
    $widgetB = $salonB->widgets()->create([
        'name' => 'B chat', 'type' => 'chat', 'public_id' => Widget::newPublicId(), 'branding' => null, 'theme' => 'marble',
    ]);

    $this->get(route('salon.chat', ['salon' => $salonB->slug, 'widget' => $widgetB->public_id]))->assertOk();
    $this->get(route('salon.chat', ['salon' => $salonA->slug, 'widget' => $widgetB->public_id]))->assertNotFound();
});

// ---------------------------------------------------------------------------
// Booking through the chat surface — the same engine, chat-tagged
// ---------------------------------------------------------------------------

it('books through the shared engine after the explicit confirm POST — source chat_widget, GHL push', function () {
    [$salon, $stylist, $service] = widgetSalon();

    $response = $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, ['surface' => 'chat']))
        ->assertCreated()
        ->assertJsonPath('success', true);

    expect($response->json('confirmation.stylist'))->toBe($stylist->name);

    $booking = Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->sole();
    expect($booking->source)->toBe(BookingSource::ChatWidget);
    expect($booking->booked_by_type)->toBe(BookedByType::ChatWidget);
    expect($booking->booked_by_user_id)->toBeNull();
    expect($booking->items()->sole()->starts_at->timezone($salon->timezone)->format('Y-m-d H:i'))->toBe('2026-06-22 14:00'); // 2 PM salon time

    $client = Client::withoutGlobalScopes()->where('salon_id', $salon->id)->sole();
    expect($client->name)->toBe('Widget Wendy');

    Bus::assertDispatched(SyncBookingToGhl::class);
});

it('keeps the classic widget untouched: no surface param still books as web_widget', function () {
    [$salon] = widgetSalon();

    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon))->assertCreated();

    expect(Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->sole()->source)
        ->toBe(BookingSource::WebWidget);
});

it('never creates anything before the confirm POST — browsing availability is read-only', function () {
    [$salon] = widgetSalon();

    $this->getJson(route('salon.widget.services', $salon))->assertOk();
    $this->getJson(route('salon.widget.availability', $salon).'?services[]='.$salon->services()->sole()->id.'&stylist=any&date=2026-06-22')->assertOk();
    $this->getJson(route('salon.widget.month', $salon).'?services[]='.$salon->services()->sole()->id.'&month=2026-06')->assertOk();

    expect(Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->count())->toBe(0);
    expect(Client::withoutGlobalScopes()->where('salon_id', $salon->id)->count())->toBe(0);
});

it('is tenant-scoped on booking: salon A\'s chat cannot book salon B\'s service or reuse B\'s token', function () {
    [$salonA] = widgetSalon();
    [$salonB, , $serviceB] = widgetSalon();

    // B's service id through A's endpoint: unknown service, nothing booked.
    $this->postJson(route('salon.widget.book', $salonA), widgetPayload($salonA, [
        'surface' => 'chat', 'service' => $serviceB->id,
    ]))->assertStatus(422);

    // B's page token on A's endpoint: bot gate refuses (foreign token).
    $this->postJson(route('salon.widget.book', $salonA), widgetPayload($salonA, [
        'surface' => 'chat', 'token' => widgetToken($salonB),
    ]))->assertStatus(422);

    expect(Booking::withoutGlobalScopes()->whereIn('salon_id', [$salonA->id, $salonB->id])->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Booking rules hold — the engine re-validates everything
// ---------------------------------------------------------------------------

it('refuses out-of-hours, out-of-window, and double-booked chat bookings', function () {
    [$salon, $stylist, $service] = widgetSalon();

    // 8 PM is outside the stylist's Monday 9–5.
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, [
        'surface' => 'chat', 'time' => '8:00 PM',
    ]))->assertStatus(409);

    // Beyond the salon's advance window.
    $far = CarbonImmutable::now($salon->timezone)->addDays($salon->max_advance_days + 30)->format('Y-m-d');
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, [
        'surface' => 'chat', 'date' => $far,
    ]))->assertStatus(409);

    // The slot books once; the second confirm loses the race with a 409.
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, ['surface' => 'chat']))->assertCreated();
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, [
        'surface' => 'chat',
        'client' => ['name' => 'Second Sam', 'phone' => '+15550302'],
    ]))->assertStatus(409);

    expect(Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Guardrails: validation, bot gate, rate limiting
// ---------------------------------------------------------------------------

it('rejects malformed client details gracefully — named fields, friendly message, nothing created', function () {
    [$salon] = widgetSalon();

    // Missing name.
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, [
        'surface' => 'chat', 'client' => ['name' => '', 'phone' => '+15550301'],
    ]))->assertStatus(422)->assertJsonPath('error', 'invalid_request');

    // Malformed email.
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, [
        'surface' => 'chat', 'client' => ['name' => 'Eve', 'phone' => '+15550301', 'email' => 'not-an-email'],
    ]))->assertStatus(422)->assertJsonPath('error', 'invalid_request');

    // An unknown surface value is refused too.
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, [
        'surface' => 'sms',
    ]))->assertStatus(422);

    expect(Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->count())->toBe(0);
});

it('applies the bot gate to chat submissions: honeypot, instant and garbled tokens all refused', function () {
    [$salon] = widgetSalon();

    foreach ([
        ['website' => 'https://spam.example'],           // honeypot filled
        ['token' => widgetToken($salon, ageSeconds: 0)], // submitted instantly
        ['token' => 'garbage'],                          // unreadable token
    ] as $bad) {
        $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, $bad + ['surface' => 'chat']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'rejected');
    }

    expect(Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->count())->toBe(0);
});

it('rate-limits the chat panel and its book endpoint per IP + salon — spam cannot flood bookings', function () {
    [$salon] = widgetSalon();
    config(['booking_api.widget_rate_limit' => 2]);

    $this->get(route('salon.chat', $salon))->assertOk();
    $this->get(route('salon.chat', $salon))->assertOk();
    $this->get(route('salon.chat', $salon))->assertStatus(429);
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon, ['surface' => 'chat']))->assertStatus(429);
});

// ---------------------------------------------------------------------------
// Settings: per-salon snippet + in-app preview
// ---------------------------------------------------------------------------

it('creates a chat widget from the type picker and shows ITS one-line snippet with the salon key', function () {
    [$salon] = widgetSalon();
    $owner = salonOwnerOf($salon);

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.widgets', ['salon' => $salon])
        ->call('createWidget', 'chat');

    $widget = $salon->widgets()->where('type', 'chat')->sole();

    $component->assertSee(__('One line, anywhere on the page'))
        ->assertSee('data-bookthestyle-chat=&quot;'.$salon->slug.'&quot;', escape: false)
        ->assertSee('data-bookthestyle-widget=&quot;'.$widget->public_id.'&quot;', escape: false)
        ->assertSee(route('chat.script'));
});

it('previews the chat panel in-app for the owner — demo included, real bookings never', function () {
    [$salon] = widgetSalon();

    $this->actingAs(salonOwnerOf($salon))
        ->get(route('salon.chat.preview', $salon))
        ->assertOk()
        ->assertSee(__('Booking assistant'))
        ->assertSee('Haircut');

    // The preview page books against the PREVIEW endpoints (non-committal
    // for real salons) — asserted by the endpoint override in the page.
    $this->actingAs(salonOwnerOf($salon))
        ->get(route('salon.chat.preview', $salon))
        ->assertSee('api\\/widget-preview', escape: false);
});

it('keeps the chat preview behind auth — the public cannot reach tenant previews', function () {
    [$salon] = widgetSalon();

    $this->get(route('salon.chat.preview', $salon))->assertRedirect();
});

// ---------------------------------------------------------------------------
// Info branches: public facts only
// ---------------------------------------------------------------------------

it('embeds only public info facts: aggregated hours, address, public phone — nothing internal', function () {
    [$salon, $stylist] = widgetSalon();
    $salon->update([
        'address_line1' => '12 Main Street', 'city' => 'Springfield', 'postal_code' => '01101',
        'business_phone' => '+1 555 010 9999',
    ]);
    // A second stylist extends Monday evening — hours are the union.
    $late = stylistWithHours($salon, 0, 11 * 60, 20 * 60);

    $response = $this->get(route('salon.chat', $salon))->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('9:00 AM - 8:00 PM')  // union of 9-5 and 11-8
        ->toContain('12 Main Street')
        ->toContain('+1 555 010 9999');

    // No client data, no internal ids beyond the public catalogue shape.
    expect($html)->not->toContain('must_change_password')->not->toContain('ghl_');
});

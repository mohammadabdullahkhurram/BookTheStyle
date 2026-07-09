<?php

use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\StylistProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| The redesigned staff-operated booking flow: real slot-engine availability
| surfaced as clickable per-line times, service lines for multi-service /
| multi-stylist, no "any available", walk-ins intact, and the server still
| the source of truth. Frozen clock: Mon 2026-06-22 12:00 UTC (08:00 EDT).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

it('surfaces only genuinely bookable start times for a line', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    Availability::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'weekday' => 0, 'kind' => 'break', 'start_minute' => 12 * 60, 'end_minute' => 13 * 60,
    ]);
    $service = serviceFor($salon, $stylist, 60);
    makeBooking($salon, salonOwnerOf($salon), $stylist, $service, '2026-06-22 10:00'); // occupies 10:00–11:00

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('items.0.service_id', (string) $service->id)
        ->set('items.0.stylist_id', (string) $stylist->id)
        ->set('date', '2026-06-22');

    $slots = $component->instance()->slotsForLine(0);

    expect($slots)->toContain('09:00');           // inside working hours, free
    expect($slots)->toContain('13:00');           // right after the break
    expect($slots)->not->toContain('08:00');      // before hours
    expect($slots)->not->toContain('10:00');      // taken by the existing booking
    expect($slots)->not->toContain('10:30');      // 60-min service would overlap it
    expect($slots)->not->toContain('12:00');      // inside the break
    expect($slots)->not->toContain('16:30');      // 60 min would overrun closing
});

it('says so clearly when a stylist has no open times on the date', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60); // Monday only
    $service = serviceFor($salon, $stylist, 60);

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('items.0.service_id', (string) $service->id)
        ->set('items.0.stylist_id', (string) $stylist->id)
        ->set('date', '2026-06-23') // Tuesday — no working hours
        ->assertSee('No open times for this stylist on this date');
});

function flowGhlSalon(): Salon
{
    $salon = bookingSalon();
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_1',
        'private_integration_token' => 'pit-secret',
        'calendar_id' => 'cal_master',
    ]);

    return $salon;
}

it('books two stylists at the same time as two lines, pushing per-stylist GHL appointments', function () {
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::sequence()
            ->push(['id' => 'ghl_a1'])->push(['id' => 'ghl_a2']),
    ]);
    $salon = flowGhlSalon();
    $anna = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $ben = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    foreach ([[$anna, 'prov_anna'], [$ben, 'prov_ben']] as [$stylist, $provider]) {
        StylistProfile::updateOrCreate(
            ['salon_id' => $salon->id, 'user_id' => $stylist->id],
            ['ghl_user_id' => $provider],
        );
    }
    $color = serviceFor($salon, $anna, 60);
    $nails = serviceFor($salon, $ben, 45);

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('clientMode', 'new')
        ->set('newName', 'Same Time Client')
        ->set('date', '2026-06-22')
        ->set('items.0.service_id', (string) $color->id)
        ->set('items.0.stylist_id', (string) $anna->id)
        ->call('addItem')
        ->set('items.1.service_id', (string) $nails->id)
        ->set('items.1.stylist_id', (string) $ben->id)
        ->call('pickTime', 0, '10:00')
        ->call('pickTime', 1, '10:00') // same time, different stylist — allowed
        ->call('save')
        ->assertHasNoErrors();

    $booking = $salon->bookings()->latest('id')->first();
    $items = $booking->items()->orderBy('id')->get();
    expect($items)->toHaveCount(2);
    expect($items[0]->stylist_id)->toBe($anna->id);
    expect($items[1]->stylist_id)->toBe($ben->id);
    expect($items[0]->starts_at->getTimestamp())->toBe($items[1]->starts_at->getTimestamp());

    // Outbound 6b intact: one appointment per stylist on their provider.
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments') && $r['assignedUserId'] === 'prov_anna');
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments') && $r['assignedUserId'] === 'prov_ben');
});

it('defaults a second line back-to-back after the first pick, adjustable', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $cut = serviceFor($salon, $stylist, 60);
    $dry = serviceFor($salon, $stylist, 30);

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('date', '2026-06-22')
        ->set('items.0.service_id', (string) $cut->id)
        ->set('items.0.stylist_id', (string) $stylist->id)
        ->call('addItem')
        ->set('items.1.service_id', (string) $dry->id)
        ->set('items.1.stylist_id', (string) $stylist->id)
        ->call('pickTime', 0, '10:00')
        ->assertSet('items.1.time', '11:00'); // back-to-back default after the 60-min first line

    // The default is only a suggestion — the booker can move it.
    $component->call('pickTime', 1, '14:00')->assertSet('items.1.time', '14:00');
});

it('never offers a slot that overlaps another line\'s pick for the same stylist', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $cut = serviceFor($salon, $stylist, 60);
    $dry = serviceFor($salon, $stylist, 30);

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('date', '2026-06-22')
        ->set('items.0.service_id', (string) $cut->id)
        ->set('items.0.stylist_id', (string) $stylist->id)
        ->call('addItem')
        ->set('items.1.service_id', (string) $dry->id)
        ->set('items.1.stylist_id', (string) $stylist->id)
        ->call('pickTime', 0, '10:00'); // occupies 10:00–11:00 in-form

    $slots = $component->instance()->slotsForLine(1);
    expect($slots)->not->toContain('10:00');
    expect($slots)->not->toContain('10:30'); // 30-min line would sit inside the first pick
    expect($slots)->toContain('09:00');
    expect($slots)->toContain('11:00');
});

it('rejects same-stylist overlap server-side even if forced past the UI', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $cut = serviceFor($salon, $stylist, 60);
    $dry = serviceFor($salon, $stylist, 30);

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('clientMode', 'new')
        ->set('newName', 'Overlap Client')
        ->set('date', '2026-06-22')
        ->set('items.0.service_id', (string) $cut->id)
        ->set('items.0.stylist_id', (string) $stylist->id)
        ->set('items.0.time', '10:00')
        ->call('addItem')
        ->set('items.1.service_id', (string) $dry->id)
        ->set('items.1.stylist_id', (string) $stylist->id)
        ->set('items.1.time', '10:30') // inside the first line's hour
        ->call('save')
        ->assertHasErrors('start');

    expect($salon->bookings()->count())->toBe(0);
});

it('has no "any available" option anywhere in the flow', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    serviceFor($salon, $stylist, 60);

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->assertDontSee('Any available');
});

it('still books a walk-in that starts now', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 8 * 60, 17 * 60); // covers "now" (08:00 EDT)
    $service = serviceFor($salon, $stylist, 60);

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('clientMode', 'new')
        ->set('newName', 'Walk-in Wanda')
        ->set('isWalkin', true)
        ->set('items.0.service_id', (string) $service->id)
        ->set('items.0.stylist_id', (string) $stylist->id)
        ->call('save')
        ->assertHasNoErrors();

    $booking = $salon->bookings()->first();
    expect($booking->is_walkin)->toBeTrue();
    expect($booking->status)->toBe(BookingStatus::Arrived);
});

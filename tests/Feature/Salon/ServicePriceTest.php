<?php

use App\Actions\Services\CreateService;
use App\Actions\Services\UpdateService;
use App\Models\Salon;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
| Display-only service prices: integer cents + per-salon currency, NULL =
| "price varies". Informational everywhere — the app never takes payments.
| Frozen clock (booking-flow tests): Mon 2026-06-22 12:00 UTC (08:00 EDT).
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------------------
// Money formatting
// ---------------------------------------------------------------------------

it('formats cents for display and passes null through', function () {
    expect(Money::format(null, 'USD'))->toBeNull();
    expect(Money::format(4500, 'USD'))->toBe('$45');
    expect(Money::format(4250, 'USD'))->toBe('$42.50');
    expect(Money::format(123456, 'USD'))->toBe('$1,234.56');
    expect(Money::format(3000, 'EUR'))->toBe('€30');
    expect(Money::format(3000, 'GBP'))->toBe('£30');
    expect(Money::format(0, 'USD'))->toBe('$0');
});

// ---------------------------------------------------------------------------
// Service create / edit
// ---------------------------------------------------------------------------

it('persists an optional price through the actions', function () {
    $salon = Salon::factory()->create();

    $priced = app(CreateService::class)->handle($salon, [
        'name' => 'Balayage', 'duration_min' => 90, 'price_cents' => 18500,
    ]);
    $unpriced = app(CreateService::class)->handle($salon, [
        'name' => 'Consultation', 'duration_min' => 15,
    ]);

    expect($priced->price_cents)->toBe(18500);
    expect($priced->priceLabel($salon->currency))->toBe('$185');
    expect($unpriced->price_cents)->toBeNull();
    expect($unpriced->priceLabel($salon->currency))->toBeNull();

    // Edit can set and clear the price; omitting the key leaves it alone.
    app(UpdateService::class)->handle($salon, $unpriced, [
        'name' => 'Consultation', 'duration_min' => 15, 'price_cents' => 2500,
    ]);
    expect($unpriced->fresh()->price_cents)->toBe(2500);

    app(UpdateService::class)->handle($salon, $unpriced, [
        'name' => 'Consultation', 'duration_min' => 15, 'price_cents' => null,
    ]);
    expect($unpriced->fresh()->price_cents)->toBeNull();

    app(UpdateService::class)->handle($salon, $priced, [
        'name' => 'Balayage', 'duration_min' => 90,
    ]);
    expect($priced->fresh()->price_cents)->toBe(18500);
});

it('saves a decimal price as cents from the services screen, blank as null', function () {
    $salon = Salon::factory()->create();

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.services.index', ['salon' => $salon])
        ->set('name', 'Gloss')
        ->set('duration_min', 45)
        ->set('price', '49.99')
        ->call('create')
        ->assertHasNoErrors();

    $gloss = $salon->services()->where('name', 'Gloss')->firstOrFail();
    expect($gloss->price_cents)->toBe(4999);

    // Blank price = "price varies" (NULL), and the list says so.
    $component->set('name', 'Consultation')->set('duration_min', 15)->set('price', '')
        ->call('create')->assertHasNoErrors();
    expect($salon->services()->where('name', 'Consultation')->firstOrFail()->price_cents)->toBeNull();
    $component->assertSee('$49.99')->assertSee(__('Varies'));

    // Editing clears a price back to "varies".
    $component->call('startEdit', $gloss->id)
        ->assertSet('editPrice', '49.99')
        ->set('editPrice', '')
        ->call('saveEdit')
        ->assertHasNoErrors();
    expect($gloss->fresh()->price_cents)->toBeNull();

    // Negative prices are rejected.
    $component->set('name', 'Bad')->set('duration_min', 30)->set('price', '-5')
        ->call('create')->assertHasErrors(['price']);
});

// ---------------------------------------------------------------------------
// Booking flow
// ---------------------------------------------------------------------------

it('shows per-line prices and an estimated total in the booking summary', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $cut = serviceFor($salon, $stylist, 60);
    $cut->update(['name' => 'Cut', 'price_cents' => 4500]);
    $color = serviceFor($salon, $stylist, 60);
    $color->update(['name' => 'Color', 'price_cents' => 12050]);

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('date', '2026-06-22')
        ->set('items.0.service_id', (string) $cut->id)
        ->set('items.0.stylist_id', (string) $stylist->id)
        ->call('pickTime', 0, '09:00')
        ->call('addItem')
        ->set('items.1.service_id', (string) $color->id)
        ->set('items.1.stylist_id', (string) $stylist->id)
        ->call('pickTime', 1, '10:00');

    $summary = $component->instance()->summary();
    expect(array_column($summary, 'price'))->toBe(['$45', '$120.50']);
    expect(array_sum(array_column($summary, 'price_cents')))->toBe(16550);

    // Rendered: each line's price and the estimated total, clearly not a charge.
    $component->assertSee('$45')->assertSee('$120.50')->assertSee('$165.50')->assertSee(__('est.'));
});

it('keeps the booking flow clean for unpriced services — no stray price text', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60); // factory price: null

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('date', '2026-06-22')
        ->set('items.0.service_id', (string) $service->id)
        ->set('items.0.stylist_id', (string) $stylist->id)
        ->call('pickTime', 0, '09:00');

    $summary = $component->instance()->summary();
    expect($summary[0]['price'])->toBeNull();

    // No estimated-price total when nothing is priced; creating still works.
    $component->assertDontSee(__('est.'))
        ->set('clientMode', 'new')
        ->set('newName', 'Priceless Pat')
        ->call('save')
        ->assertHasNoErrors();
});

it('shows the price per service and an estimated total on the booking detail', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $service->update(['price_cents' => 7500]);
    $booking = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 14:00');

    Livewire::actingAs($owner)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->call('openBooking', $booking->id)
        ->assertSet('showDetail', true)
        ->assertSee('$75')
        ->assertSee(__('Estimated price'));
});

it('leaves the booking detail free of price rows for unpriced services', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 14:00');

    Livewire::actingAs($owner)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->call('openBooking', $booking->id)
        ->assertSet('showDetail', true)
        ->assertDontSee(__('Estimated price'));
});

// ---------------------------------------------------------------------------
// Per-salon currency
// ---------------------------------------------------------------------------

it('formats prices in the salon\'s own currency, saved from settings', function () {
    $salon = Salon::factory()->create();

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->assertSet('currency', 'USD')
        ->set('currency', 'EUR')
        ->call('saveCurrency')
        ->assertHasNoErrors();

    expect($salon->refresh()->currency)->toBe('EUR');

    $service = app(CreateService::class)->handle($salon, [
        'name' => 'Cut', 'duration_min' => 45, 'price_cents' => 4000,
    ]);
    expect($service->priceLabel($salon->currency))->toBe('€40');
});

it('rejects a currency outside the supported list', function () {
    $salon = Salon::factory()->create();

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('currency', 'XXX')
        ->call('saveCurrency')
        ->assertHasErrors(['currency']);

    expect($salon->refresh()->currency)->toBe('USD');
});

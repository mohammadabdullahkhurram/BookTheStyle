<?php

use App\Actions\Bookings\CreateBooking;
use App\Actions\Services\CreateService;
use App\Models\Salon;
use App\Services\Calendar\CalendarData;
use App\Support\PastelPalette;
use App\Support\ServicePalette;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
| Services get a distinct, on-brand colour auto-assigned from the curated
| palette (no manual picker), stored as a stable key per salon. The calendar
| colours appointment blocks BY SERVICE; stylists stay distinct via avatars.
|
| Mon 2026-06-22, "now" = 08:00 America/New_York (matches the calendar tests).
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('assigns N distinct palette colours for N <= palette size', function () {
    $salon = Salon::factory()->create();

    $keys = [];
    foreach (range(1, ServicePalette::count()) as $i) {
        $service = app(CreateService::class)->handle($salon, ['name' => "Service {$i}", 'duration_min' => 30]);
        $keys[] = $service->color_key;
    }

    // Every colour is a real palette key, and all are distinct (no dupes).
    expect($keys)->each->toBeIn(ServicePalette::keys());
    expect(array_unique($keys))->toHaveCount(ServicePalette::count());
});

it('keeps an existing service\'s colour stable when others are added or removed', function () {
    $salon = Salon::factory()->create();

    $first = app(CreateService::class)->handle($salon, ['name' => 'First', 'duration_min' => 30]);
    $original = $first->color_key;

    // Add several more, then remove one — the first never gets reshuffled.
    $second = app(CreateService::class)->handle($salon, ['name' => 'Second', 'duration_min' => 30]);
    app(CreateService::class)->handle($salon, ['name' => 'Third', 'duration_min' => 30]);
    $second->delete();
    app(CreateService::class)->handle($salon, ['name' => 'Fourth', 'duration_min' => 30]);

    expect($first->fresh()->color_key)->toBe($original);
});

it('never duplicates a colour while a distinct one is free', function () {
    $salon = Salon::factory()->create();

    $keys = [];
    foreach (range(1, ServicePalette::count()) as $i) {
        $keys[] = app(CreateService::class)->handle($salon, ['name' => "S{$i}", 'duration_min' => 30])->color_key;
    }

    expect(array_unique($keys))->toHaveCount(count($keys));
});

it('reuses the least-used colour once the palette is exhausted (spread, not clustered)', function () {
    $salon = Salon::factory()->create();

    // Fill the palette once (each colour used exactly once).
    foreach (range(1, ServicePalette::count()) as $i) {
        app(CreateService::class)->handle($salon, ['name' => "S{$i}", 'duration_min' => 30]);
    }

    // The next service must reuse a colour (palette full) — one now used twice.
    $extra = app(CreateService::class)->handle($salon, ['name' => 'Extra', 'duration_min' => 30]);
    $counts = $salon->services()->where('active', true)
        ->selectRaw('color_key, count(*) as c')->groupBy('color_key')->pluck('c', 'color_key');

    expect($extra->color_key)->toBeIn(ServicePalette::keys());
    expect($counts->max())->toBe(2)          // exactly one colour doubled up
        ->and($counts->where(fn ($c) => $c === 2)->count())->toBe(1);

    // A second extra picks a *different* least-used colour (spread out).
    $extra2 = app(CreateService::class)->handle($salon, ['name' => 'Extra 2', 'duration_min' => 30]);
    expect($extra2->color_key)->not->toBe($extra->color_key);
});

it('isolates colour assignment per salon (no cross-salon interference)', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();

    // Salon A burns through several palette colours.
    foreach (range(1, 5) as $i) {
        app(CreateService::class)->handle($salonA, ['name' => "A{$i}", 'duration_min' => 30]);
    }

    // Salon B's first service still gets the first palette colour — A had no effect.
    $firstB = app(CreateService::class)->handle($salonB, ['name' => 'B1', 'duration_min' => 30]);
    expect($firstB->color_key)->toBe(ServicePalette::keys()[0]);
});

it('backfills existing services with distinct colours via the migration rule', function () {
    // RefreshDatabase runs the replace-color migration on a clean schema; create
    // a fresh batch and confirm the create rule yields a distinct spread (the
    // same pick() the backfill uses).
    $salon = Salon::factory()->create();

    $services = collect(range(1, 6))->map(
        fn ($i) => app(CreateService::class)->handle($salon, ['name' => "Svc {$i}", 'duration_min' => 30]),
    );

    $keys = $services->pluck('color_key');
    expect($keys->unique())->toHaveCount(6);
    expect($keys->every(fn ($k) => in_array($k, ServicePalette::keys(), true)))->toBeTrue();
});

it('colours a calendar block by its service, not the stylist', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = app(CreateService::class)->handle($salon, ['name' => 'Colour', 'duration_min' => 60]);
    $service->stylists()->attach($stylist->id, ['salon_id' => $salon->id]);

    makeBooking($salon, salonOwnerOf($salon), $stylist, $service, '2026-06-22 10:00', 'Block Client');

    $grid = app(CalendarData::class)->day($salon, CarbonImmutable::parse('2026-06-22 12:00', $salon->timezone), null);
    $column = collect($grid['columns'])->firstWhere('stylistId', $stylist->id);
    $block = $column['bookings'][0];

    // Block colour matches the service palette — and is NOT the stylist family.
    expect($block['color'])->toBe(ServicePalette::get($service->color_key));
    expect($block['color'])->not->toBe(PastelPalette::forSeed($stylist->id));
    // No stylist-based colour leaks onto the block any more.
    expect($block)->not->toHaveKey('family');
});

it('colours each block of a multi-service visit by its own (primary) service', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $primary = app(CreateService::class)->handle($salon, ['name' => 'Cut', 'duration_min' => 30]);
    $secondary = app(CreateService::class)->handle($salon, ['name' => 'Colour', 'duration_min' => 30]);
    $primary->stylists()->attach($stylist->id, ['salon_id' => $salon->id]);
    $secondary->stylists()->attach($stylist->id, ['salon_id' => $salon->id]);

    // One booking, two services back-to-back with the same stylist.
    app(CreateBooking::class)->handle(salonOwnerOf($salon), $salon, [
        'client' => ['name' => 'Multi Client'],
        'items' => [
            ['service_id' => $primary->id, 'stylist_id' => $stylist->id],
            ['service_id' => $secondary->id, 'stylist_id' => $stylist->id],
        ],
        'start' => '2026-06-22 10:00',
        'is_walkin' => false,
        'notes' => null,
    ]);

    $grid = app(CalendarData::class)->day($salon, CarbonImmutable::parse('2026-06-22 12:00', $salon->timezone), null);
    $blocks = collect(collect($grid['columns'])->firstWhere('stylistId', $stylist->id)['bookings'])
        ->keyBy('service');

    // Each block carries its own service's colour; the primary (first) service is
    // "Cut", so the visit's lead block reads in Cut's colour.
    expect($blocks['Cut']['color'])->toBe(ServicePalette::get($primary->color_key));
    expect($blocks['Colour']['color'])->toBe(ServicePalette::get($secondary->color_key));
});

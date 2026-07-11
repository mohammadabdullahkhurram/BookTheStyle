<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| The Clients directory: one aggregated query per page — visits (distinct
| visit groups), services, spend, last/upcoming visit, flags — with search,
| sort, and filters. Frozen clock: Mon 2026-06-22 12:00 UTC (08:00 EDT).
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

function dirBooking(Salon $salon, Client $client, User $stylist, Service $service, string $start, BookingStatus $status, ?string $visitGroup = null): Booking
{
    $startAt = CarbonImmutable::parse($start, $salon->timezone);
    $booking = Booking::factory()->for($salon)->for($client)->create([
        'status' => $status, 'visit_group_id' => $visitGroup,
    ]);
    BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => $service->id,
        'stylist_id' => $stylist->id,
        'starts_at' => $startAt,
        'ends_at' => $startAt->addMinutes($service->duration_min),
    ]);

    return $booking;
}

/** @return array{0: Salon, 1: User, 2: User, 3: Service, 4: Service, 5: Client, 6: Client, 7: Client} */
function directorySalon(): array
{
    $salon = bookingSalon();
    $anna = stylistOf($salon);
    $ben = stylistOf($salon);
    $cut = serviceFor($salon, $anna, 45);
    $cut->update(['name' => 'Cut', 'price_cents' => 5000]);
    $color = serviceFor($salon, $ben, 90);
    $color->update(['name' => 'Color', 'price_cents' => 12000]);

    // Vera: ONE completed two-service visit (split bookings, shared group),
    // an upcoming booking, allergies, and a note.
    $vera = Client::factory()->for($salon)->create([
        'name' => 'Vera Visits', 'phone' => '+15550101', 'email' => 'vera@example.com',
        'allergies' => 'PPD', 'preferred_stylist_id' => $anna->id,
        'created_at' => '2026-01-05 12:00:00',
    ]);
    $group = (string) Str::uuid();
    dirBooking($salon, $vera, $anna, $cut, '2026-06-10 10:00', BookingStatus::Completed, $group);
    dirBooking($salon, $vera, $ben, $color, '2026-06-10 11:00', BookingStatus::Completed, $group);
    dirBooking($salon, $vera, $anna, $cut, '2026-04-01 10:00', BookingStatus::Completed); // an earlier solo visit
    dirBooking($salon, $vera, $anna, $cut, '2026-06-25 09:00', BookingStatus::Booked);
    ClientNote::factory()->for($salon)->create(['client_id' => $vera->id]);

    // Max: one completed cut in May, one no-show; no upcoming.
    $max = Client::factory()->for($salon)->create([
        'name' => 'Max May', 'phone' => '+15550202', 'email' => 'max@example.com',
        'created_at' => '2026-02-01 12:00:00',
    ]);
    dirBooking($salon, $max, $anna, $cut, '2026-05-04 10:00', BookingStatus::Completed);
    dirBooking($salon, $max, $anna, $cut, '2026-06-01 10:00', BookingStatus::NoShow);

    // Newbie: brand new, no bookings.
    $newbie = Client::factory()->for($salon)->create([
        'name' => 'Nina New', 'phone' => '+15550303', 'email' => 'nina@example.com',
        'created_at' => '2026-06-20 12:00:00',
    ]);

    return [$salon, $anna, $ben, $cut, $color, $vera, $max, $newbie];
}

function directoryRows(Salon $salon, array $props = []): Collection
{
    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.clients.index', ['salon' => $salon]);

    foreach ($props as $key => $value) {
        $component->set($key, $value);
    }

    return collect($component->instance()->clients->items())->keyBy('name');
}

// ---------------------------------------------------------------------------
// Stats + isolation
// ---------------------------------------------------------------------------

it('computes per-row stats from bookings and excludes other salons', function () {
    [$salonA, $anna] = directorySalon();

    // Salon B noise that must never leak into A's directory.
    $salonB = bookingSalon();
    $stylistB = stylistOf($salonB);
    $serviceB = serviceFor($salonB, $stylistB, 60);
    $serviceB->update(['price_cents' => 99900]);
    $clientB = Client::factory()->for($salonB)->create(['name' => 'Bella B']);
    dirBooking($salonB, $clientB, $stylistB, $serviceB, '2026-06-10 10:00', BookingStatus::Completed);

    $rows = directoryRows($salonA);

    expect($rows->keys()->sort()->values()->all())->toBe(['Max May', 'Nina New', 'Vera Visits']);

    $vera = $rows['Vera Visits'];
    expect((int) $vera->total_visits)->toBe(2);       // the two-service visit is ONE visit + the April solo
    expect((int) $vera->total_services)->toBe(3);     // …two services + the solo cut
    expect((int) $vera->spent_cents)->toBe(22000);    // $50 + $120 + $50
    expect($vera->last_visit_at)->toContain('2026-06-10');
    expect($vera->upcoming_at)->toContain('2026-06-25');
    expect((int) $vera->no_show_count)->toBe(0);
    expect((int) $vera->notes_count)->toBe(1);
    expect($vera->preferredStylist->name)->toBe($anna->name);

    $max = $rows['Max May'];
    expect((int) $max->total_visits)->toBe(1);
    expect((int) $max->total_services)->toBe(1);      // the no-show earns no service count
    expect((int) $max->spent_cents)->toBe(5000);
    expect((int) $max->no_show_count)->toBe(1);
    expect($max->upcoming_at)->toBeNull();

    $nina = $rows['Nina New'];
    expect((int) $nina->total_visits)->toBe(0);
    expect($nina->last_visit_at)->toBeNull();
    expect((int) ($nina->spent_cents ?? 0))->toBe(0);
});

// ---------------------------------------------------------------------------
// Search / sort / filter
// ---------------------------------------------------------------------------

it('searches by name, phone, and email', function () {
    [$salon] = directorySalon();

    expect(directoryRows($salon, ['search' => 'vera'])->keys()->all())->toBe(['Vera Visits']);
    expect(directoryRows($salon, ['search' => '+15550202'])->keys()->all())->toBe(['Max May']);
    expect(directoryRows($salon, ['search' => 'nina@example.com'])->keys()->all())->toBe(['Nina New']);
});

it('sorts by visits, recency, spend, name, and newest', function () {
    [$salon] = directorySalon();

    expect(directoryRows($salon, ['sort' => 'visits'])->keys()->first())->toBe('Vera Visits');
    expect(directoryRows($salon, ['sort' => 'recent'])->keys()->first())->toBe('Vera Visits');   // June beats May
    expect(directoryRows($salon, ['sort' => 'spent'])->keys()->all())->toBe(['Vera Visits', 'Max May', 'Nina New']);
    expect(directoryRows($salon, ['sort' => 'name'])->keys()->first())->toBe('Max May');
    expect(directoryRows($salon, ['sort' => 'newest'])->keys()->first())->toBe('Nina New');
});

it('filters by stylist, service, upcoming, and new — and combines with search', function () {
    [$salon, $anna, $ben, $cut, $color] = directorySalon();

    // Ben has only served Vera (the color half of her visit).
    expect(directoryRows($salon, ['stylistFilter' => (string) $ben->id])->keys()->all())->toBe(['Vera Visits']);
    // Anna has served Vera and Max.
    expect(directoryRows($salon, ['stylistFilter' => (string) $anna->id])->keys()->sort()->values()->all())->toBe(['Max May', 'Vera Visits']);

    expect(directoryRows($salon, ['serviceFilter' => (string) $color->id])->keys()->all())->toBe(['Vera Visits']);
    expect(directoryRows($salon, ['upcomingOnly' => true])->keys()->all())->toBe(['Vera Visits']);
    expect(directoryRows($salon, ['newOnly' => true])->keys()->all())->toBe(['Nina New']);

    // Combined: Anna's clients matching "max".
    expect(directoryRows($salon, ['stylistFilter' => (string) $anna->id, 'search' => 'max'])->keys()->all())->toBe(['Max May']);
    // Combined with no survivors → no-results state.
    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.clients.index', ['salon' => $salon])
        ->set('stylistFilter', (string) $ben->id)
        ->set('search', 'max')
        ->assertSee(__('No clients match. Adjust the search or filters.'));
});

// ---------------------------------------------------------------------------
// Rendering: links, flags, states
// ---------------------------------------------------------------------------

it('links every row to the profile and flags allergies', function () {
    [$salon, , , , , $vera] = directorySalon();

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.clients.index', ['salon' => $salon])
        ->assertSeeHtml(route('salon.client', ['salon' => $salon, 'clientId' => $vera->id]))
        ->assertSee(__('Allergy'))
        ->assertSee(__('New'))     // Nina's badge
        ->assertSee('$220');       // Vera's estimated spend
});

it('shows the empty state for a salon with no clients', function () {
    $salon = bookingSalon();

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.clients.index', ['salon' => $salon])
        ->assertSee(__('No clients yet. They appear here with their first booking.'));
});

// ---------------------------------------------------------------------------
// Performance
// ---------------------------------------------------------------------------

it('paginates and stays N+1-free regardless of client count', function () {
    [$salon, $anna, , $cut] = directorySalon();

    // 30 more clients, each with a booking — 33 total, page size 25.
    foreach (range(1, 30) as $i) {
        $client = Client::factory()->for($salon)->create(['name' => sprintf('Bulk %02d', $i)]);
        dirBooking($salon, $client, $anna, $cut, '2026-06-15 10:00', BookingStatus::Completed);
    }

    DB::enableQueryLog();
    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.clients.index', ['salon' => $salon]);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($component->instance()->clients->total())->toBe(33);
    expect(count($component->instance()->clients->items()))->toBe(25); // page 1

    // The whole render — auth, summary, filters, 25 stat-laden rows — stays
    // a bounded handful of queries; per-client work would blow far past this.
    expect($queries)->toBeLessThan(20);

    // Page 2 holds the remainder.
    $page2 = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.clients.index', ['salon' => $salon])
        ->call('nextPage');
    expect(count($page2->instance()->clients->items()))->toBe(8);
});

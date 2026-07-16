<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
| Reports (owner/admin): aggregate metrics over existing bookings for a
| salon-local date range. Frozen clock: Mon 2026-06-22 12:00 UTC (08:00 EDT).
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

/** One booking with a single item for reporting fixtures. */
function reportVisit(Salon $salon, User $stylist, Service $service, string $start, BookingStatus $status, BookingSource $source = BookingSource::InApp): Booking
{
    $startAt = CarbonImmutable::parse($start, $salon->timezone);
    $booking = Booking::factory()->for($salon)->for(Client::factory()->for($salon))->create([
        'status' => $status,
        'source' => $source,
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

/** @return array{0: Salon, 1: User, 2: User} salon + two stylists, fixtures seeded */
function seededReportSalon(): array
{
    $salon = bookingSalon();
    $anna = stylistOf($salon);
    $ben = stylistOf($salon);

    $cut = serviceFor($salon, $anna, 45);
    $cut->update(['name' => 'Cut', 'price_cents' => 5000]);
    $color = serviceFor($salon, $anna, 90);
    $color->update(['name' => 'Color', 'price_cents' => 12000]);
    $consult = serviceFor($salon, $ben, 15);
    $consult->update(['name' => 'Consult', 'price_cents' => null]);

    // June (in range for "2026-06-01 .. 2026-06-30"):
    reportVisit($salon, $anna, $cut, '2026-06-10 10:00', BookingStatus::Completed);                                // in_app
    reportVisit($salon, $anna, $color, '2026-06-12 10:00', BookingStatus::Completed, BookingSource::VoiceAi);
    reportVisit($salon, $ben, $consult, '2026-06-15 10:00', BookingStatus::Completed, BookingSource::ChatWidget);  // unpriced
    reportVisit($salon, $ben, $cut, '2026-06-16 10:00', BookingStatus::NoShow, BookingSource::VoiceAi);
    reportVisit($salon, $anna, $color, '2026-06-17 10:00', BookingStatus::Cancelled);                              // excluded from stylist/service counts
    reportVisit($salon, $anna, $cut, '2026-06-29 09:00', BookingStatus::Booked, BookingSource::GhlManual);         // upcoming

    // Out of range (May) — must never leak in.
    reportVisit($salon, $anna, $cut, '2026-05-20 10:00', BookingStatus::Completed);

    return [$salon, $anna, $ben];
}

function reportFor(Salon $salon, string $from, string $to): array
{
    return Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.reports', ['salon' => $salon])
        ->set('from', $from)
        ->set('to', $to)
        ->instance()->report();
}

// ---------------------------------------------------------------------------
// Metrics
// ---------------------------------------------------------------------------

it('computes totals, no-show rate, and estimated revenue over the range', function () {
    [$salon] = seededReportSalon();

    $r = reportFor($salon, '2026-06-01', '2026-06-30');

    expect($r['total'])->toBe(6);        // May booking excluded
    expect($r['completed'])->toBe(3);
    expect($r['cancelled'])->toBe(1);
    expect($r['no_shows'])->toBe(1);
    expect($r['no_show_rate'])->toBe(20.0); // 1 of 5 non-cancelled

    // Revenue: completed priced items only (Cut $50 + Color $120); the
    // unpriced completed Consult is counted separately for the caveat.
    expect($r['revenue_cents'])->toBe(17000);
    expect($r['unpriced_completed_items'])->toBe(1);
});

it('breaks bookings down by source with shares — the AI channels visible', function () {
    [$salon] = seededReportSalon();

    $r = reportFor($salon, '2026-06-01', '2026-06-30');
    $mix = collect($r['source_mix'])->keyBy('source');

    expect($mix['in_app']['count'])->toBe(2);       // completed + cancelled
    expect($mix['voice_ai']['count'])->toBe(2);     // completed + no-show
    expect($mix['chat_widget']['count'])->toBe(1);
    expect($mix['ghl_manual']['count'])->toBe(1);
    expect($mix['voice_ai']['share'])->toBe(33.3);

    // The rendered page highlights the AI share.
    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.reports', ['salon' => $salon])
        ->set('from', '2026-06-01')->set('to', '2026-06-30')
        ->assertSee(__('Where bookings came from'))
        ->assertSee('booked by AI');
});

it('counts per-stylist activity and top services, excluding cancelled', function () {
    [$salon, $anna, $ben] = seededReportSalon();

    $r = reportFor($salon, '2026-06-01', '2026-06-30');

    $stylists = collect($r['stylists'])->keyBy('stylist_id');
    expect($stylists[$anna->id]['total'])->toBe(3);      // cut, color, upcoming cut — cancelled color excluded
    expect($stylists[$anna->id]['completed'])->toBe(2);
    expect($stylists[$ben->id]['total'])->toBe(2);       // consult + no-show cut
    expect($stylists[$ben->id]['completed'])->toBe(1);

    $services = collect($r['top_services'])->keyBy('name');
    expect($services['Cut']['count'])->toBe(3);          // completed + no-show + upcoming
    expect($services['Cut']['revenue_cents'])->toBe(5000); // only the completed one earns
    expect($services['Color']['count'])->toBe(1);        // cancelled excluded
    expect($services['Color']['revenue_cents'])->toBe(12000);
    expect($services['Consult']['revenue_cents'])->toBeNull(); // unpriced
    expect($r['top_services'][0]['name'])->toBe('Cut');  // ordered by count
});

// ---------------------------------------------------------------------------
// Range behavior
// ---------------------------------------------------------------------------

it('filters strictly by the selected range and presets update the dates', function () {
    [$salon] = seededReportSalon();

    expect(reportFor($salon, '2026-06-09', '2026-06-11')['total'])->toBe(1);
    expect(reportFor($salon, '2026-05-01', '2026-05-31')['total'])->toBe(1); // the May booking

    // Presets recompute from/to in the salon's timezone (frozen: Mon Jun 22).
    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.reports', ['salon' => $salon])
        ->assertSet('from', '2026-06-01')->assertSet('to', '2026-06-30') // month default
        ->set('preset', 'week')
        ->assertSet('from', '2026-06-22')->assertSet('to', '2026-06-28');
});

it('shows a clean empty state for a range with no bookings', function () {
    [$salon] = seededReportSalon();

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.reports', ['salon' => $salon])
        ->set('from', '2026-01-01')->set('to', '2026-01-31')
        ->assertSee(__('No bookings in this range.'))
        ->assertDontSee(__('Where bookings came from'));
});

// ---------------------------------------------------------------------------
// Tenant isolation + permissions
// ---------------------------------------------------------------------------

it('never counts another salon\'s bookings', function () {
    [$salonA] = seededReportSalon();

    $salonB = bookingSalon();
    $stylistB = stylistOf($salonB);
    $serviceB = serviceFor($salonB, $stylistB, 60);
    $serviceB->update(['price_cents' => 99900]);
    reportVisit($salonB, $stylistB, $serviceB, '2026-06-10 10:00', BookingStatus::Completed, BookingSource::VoiceAi);

    $r = reportFor($salonA, '2026-06-01', '2026-06-30');
    expect($r['total'])->toBe(6);
    expect($r['revenue_cents'])->toBe(17000); // salon B's $999 never leaks

    $rB = reportFor($salonB, '2026-06-01', '2026-06-30');
    expect($rB['total'])->toBe(1);
    expect($rB['revenue_cents'])->toBe(99900);
});

it('admits owners and admins — incl. front desk (admin role) — and refuses stylists', function () {
    $salon = bookingSalon();

    $this->actingAs(salonOwnerOf($salon))->get(route('salon.reports', $salon))->assertOk();
    $this->actingAs(salonAdminOf($salon))->get(route('salon.reports', $salon))->assertOk();
    $this->actingAs(frontDeskOf($salon))->get(route('salon.reports', $salon))->assertOk();
    $this->actingAs(stylistOf($salon))->get(route('salon.reports', $salon))->assertForbidden();
});

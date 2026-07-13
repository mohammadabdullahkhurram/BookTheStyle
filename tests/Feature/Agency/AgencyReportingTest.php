<?php

use App\Enums\AgencyRole;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\StaffType;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Reporting\AgencyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| The agency console improvements: the Dashboard rename + nav order, the
| agency-wide Reporting (AgencyReport aggregates across ALL the agency's
| salons in a bounded number of grouped queries), and the Users directory.
| Frozen clock: Mon 2026-06-22 12:00 UTC.
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

function reportingAgencyOwner(Agency $agency): User
{
    return User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
}

/** One booking with one item, for aggregate fixtures. */
function agencyVisit(Salon $salon, BookingStatus $status, BookingSource $source, string $start = '2026-06-10 10:00', ?int $priceCents = 5000): Booking
{
    $stylist = stylistOf($salon);
    $service = Service::factory()->create(['salon_id' => $salon->id, 'duration_min' => 45, 'price_cents' => $priceCents]);
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
        'ends_at' => $startAt->addMinutes(45),
    ]);

    return $booking;
}

// ---------------------------------------------------------------------------
// Rename + nav
// ---------------------------------------------------------------------------

it('renames the console to Dashboard and orders the agency nav with New salon pinned', function () {
    $agency = Agency::factory()->create();
    Salon::factory()->for($agency)->create();
    $owner = reportingAgencyOwner($agency);

    $response = $this->actingAs($owner)->get(route('agency.overview'))->assertOk();
    $html = $response->getContent();

    // Nav: Dashboard → Salons → Reporting → Users, then the pinned New salon.
    $response->assertSee(route('agency.reports'))
        ->assertSee('Reporting')
        ->assertSee('New salon');
    $offset = 0;
    foreach (['aria-label="Dashboard"', 'aria-label="Salons"', 'aria-label="Reporting"', 'aria-label="Users"', 'aria-label="New salon"'] as $needle) {
        $position = strpos($html, $needle, $offset);
        expect($position)->not->toBeFalse();
        $offset = $position + 1;
    }

    // The old label is gone everywhere the user sees.
    expect($html)->not->toContain('Agency console');
});

// ---------------------------------------------------------------------------
// Agency-wide reporting
// ---------------------------------------------------------------------------

it('aggregates every salon of the agency — totals, per-salon breakdown, source mix, per-currency revenue', function () {
    $agency = Agency::factory()->create();
    $salonA = Salon::factory()->for($agency)->create(['name' => 'Alpha', 'currency' => 'USD']);
    $salonB = Salon::factory()->for($agency)->create(['name' => 'Beta', 'currency' => 'EUR']);
    // Another agency's salon: identical bookings, must never leak in.
    $foreign = Salon::factory()->create(['name' => 'Foreign']);

    agencyVisit($salonA, BookingStatus::Completed, BookingSource::VoiceAi);          // $50 USD
    agencyVisit($salonA, BookingStatus::Completed, BookingSource::WebWidget, priceCents: 2000); // $20 USD
    agencyVisit($salonA, BookingStatus::NoShow, BookingSource::InApp);
    agencyVisit($salonB, BookingStatus::Completed, BookingSource::VoiceAi, priceCents: 7000);   // €70
    agencyVisit($salonB, BookingStatus::Cancelled, BookingSource::InApp);
    agencyVisit($foreign, BookingStatus::Completed, BookingSource::VoiceAi);
    // Out of range — excluded.
    agencyVisit($salonA, BookingStatus::Completed, BookingSource::InApp, start: '2026-05-01 10:00');

    $report = app(AgencyReport::class)->build(
        $agency,
        CarbonImmutable::parse('2026-06-01', 'UTC'),
        CarbonImmutable::parse('2026-07-01', 'UTC'),
    );

    expect($report['totals']['total'])->toBe(5);
    expect($report['totals']['completed'])->toBe(3);
    expect($report['totals']['cancelled'])->toBe(1);
    expect($report['totals']['no_shows'])->toBe(1);
    expect($report['totals']['no_show_rate'])->toBe(25.0); // 1 of 4 non-cancelled

    // Revenue grouped per currency — never summed across currencies.
    expect($report['revenue'])->toBe(['EUR' => 7000, 'USD' => 7000]);

    // Source mix agency-wide: the voice-AI value in one number.
    $mix = collect($report['source_mix'])->keyBy('source');
    expect($mix[BookingSource::VoiceAi->value]['count'])->toBe(2);
    expect($mix[BookingSource::WebWidget->value]['count'])->toBe(1);

    // Per-salon breakdown, ranked most-active first; the foreign salon absent.
    expect(collect($report['salons'])->pluck('name')->all())->toBe(['Alpha', 'Beta']);
    $alpha = $report['salons'][0];
    expect($alpha['total'])->toBe(3);
    expect($alpha['no_show_rate'])->toBe(33.3);
    expect($alpha['revenue_cents'])->toBe(7000);
    expect($alpha['sources'][BookingSource::VoiceAi->value])->toBe(1);
});

it('keeps the aggregate query count bounded regardless of salon count', function () {
    $agency = Agency::factory()->create();
    foreach (range(1, 5) as $i) {
        agencyVisit(Salon::factory()->for($agency)->create(), BookingStatus::Completed, BookingSource::InApp);
    }

    DB::enableQueryLog();
    app(AgencyReport::class)->build($agency, CarbonImmutable::parse('2026-06-01', 'UTC'), CarbonImmutable::parse('2026-07-01', 'UTC'));
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThanOrEqual(5); // salons + 3 grouped fact queries
});

it('gates agency reporting to the console: agency operators in, salon staff out', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();

    $this->actingAs(reportingAgencyOwner($agency))->get(route('agency.reports'))
        ->assertOk()
        ->assertSee('Reporting')
        ->assertSee('data-theme="glacier"', false); // Glacier on the agency shell

    // Salon staff (no agency role) never reach agency reporting.
    $this->actingAs(salonOwnerOf($salon))->get(route('agency.reports'))->assertForbidden();
    $this->actingAs(stylistOf($salon))->get(route('agency.reports'))->assertForbidden();
});

// ---------------------------------------------------------------------------
// Users directory
// ---------------------------------------------------------------------------

it('lists agency operators AND salon staff with roles, salons and status — search and filters work', function () {
    $agency = Agency::factory()->create();
    $salonA = Salon::factory()->for($agency)->create(['name' => 'Alpha']);
    $salonB = Salon::factory()->for($agency)->create(['name' => 'Beta']);
    $owner = reportingAgencyOwner($agency);
    $owner->update(['name' => 'Agatha Agency']);

    $stylistA = stylistOf($salonA);
    $stylistA->update(['name' => 'Sasha Scissors', 'must_change_password' => true]);
    $frontB = frontDeskOf($salonB);
    $frontB->update(['name' => 'Fred Frontdesk']);

    // Another agency's staff never appear.
    $foreignStylist = stylistOf(Salon::factory()->create());
    $foreignStylist->update(['name' => 'Zora Foreign']);

    $component = Livewire::actingAs($owner)->test('pages::agency.users.index');

    $component->assertSee('Agatha Agency')
        ->assertSee('Sasha Scissors')
        ->assertSee('Fred Frontdesk')
        ->assertSee('Alpha')
        ->assertSee('Beta')
        ->assertSee('Invited')  // Sasha still on her temp password
        ->assertDontSee('Zora Foreign');

    // Search narrows across both groups.
    $component->set('search', 'Sasha')
        ->assertSee('Sasha Scissors')
        ->assertDontSee('Fred Frontdesk')
        ->assertDontSee('Agatha Agency');

    // Role filter: front desk only.
    $component->set('search', '')->set('role', StaffType::FrontDesk->value)
        ->assertSee('Fred Frontdesk')
        ->assertDontSee('Sasha Scissors');

    // Salon filter.
    $component->set('role', '')->set('salonId', (string) $salonA->id)
        ->assertSee('Sasha Scissors')
        ->assertDontSee('Fred Frontdesk');

    // Scope: agency only.
    $component->set('salonId', '')->set('scope', 'agency')
        ->assertSee('Agatha Agency')
        ->assertDontSee('Sasha Scissors');
});

it('keeps the users directory agency-gated', function () {
    $salon = Salon::factory()->create();

    $this->actingAs(salonOwnerOf($salon))->get(route('agency.users.index'))->assertForbidden();
});

<?php

use App\Jobs\SyncAvailabilityToGhl;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\StylistProfile;
use App\Models\TimeOff;
use App\Support\AvailabilitySummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

/*
| The redesigned availability screen: staff cards (own card first) with a
| weekly summary line, a right-side schedule panel with "Weekly hours" and
| "Date-specific hours" tabs, read view by default, and an Edit action gated
| by AvailabilityAccess — plus the panel save still driving the 6e GHL sync.
*/

function apWindow(Salon $salon, int $userId, int $weekday, int $start, int $end): void
{
    Availability::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $userId,
        'weekday' => $weekday, 'kind' => 'work',
        'start_minute' => $start, 'end_minute' => $end,
    ]);
}

// ---------------------------------------------------------------------------
// Cards
// ---------------------------------------------------------------------------

it('renders a card for every stylist with the current user first and a summary line', function () {
    $salon = Salon::factory()->create();
    $me = stylistOf($salon);
    $anna = stylistOf($salon);
    $anna->update(['name' => 'Aaa First-Alphabetically']);

    foreach (range(0, 4) as $weekday) {
        apWindow($salon, $me->id, $weekday, 480, 1020); // Mon–Fri 8:00–17:00
    }

    test()->actingAs($me);

    $component = Livewire::test('pages::salon.availability.index', ['salon' => $salon]);

    // Own card leads even against an alphabetically-earlier colleague.
    $cards = $component->instance()->cards;
    expect($cards[0]['id'])->toBe($me->id);
    expect($cards[0]['summary'])->toBe('Weekdays, 8:00 AM – 5:00 PM');

    $component->assertSee('Other staff members')
        ->assertSee('Aaa First-Alphabetically')
        ->assertSee('Weekdays, 8:00 AM – 5:00 PM')
        ->assertSee('Unavailable'); // the colleague has no hours yet
});

it('summarises weekly hours sensibly for every shape of week', function () {
    $week = fn (array $days, array $window = [[480, 1020]]) => collect($days)
        ->mapWithKeys(fn (int $d) => [$d => $window])->all();

    expect(AvailabilitySummary::line([]))->toBe('Unavailable');
    expect(AvailabilitySummary::line($week([0, 1, 2, 3, 4])))->toBe('Weekdays, 8:00 AM – 5:00 PM');
    expect(AvailabilitySummary::line($week([0, 1, 2, 3, 4, 5, 6])))->toBe('Every day, 8:00 AM – 5:00 PM');
    expect(AvailabilitySummary::line($week([5, 6])))->toBe('Weekends, 8:00 AM – 5:00 PM');
    expect(AvailabilitySummary::line($week([0, 1, 2, 3, 4, 5])))->toBe('Mon – Sat, 8:00 AM – 5:00 PM');
    expect(AvailabilitySummary::line($week([0, 2, 4])))->toBe('Mon, Wed, Fri, 8:00 AM – 5:00 PM');

    // Different hours across days: the day set with "varies".
    expect(AvailabilitySummary::line([0 => [[480, 1020]], 1 => [[600, 900]]]))->toBe('Mon, Tue, varies');

    // A split shift reads as both windows.
    expect(AvailabilitySummary::line([0 => [[540, 720], [840, 1080]]]))
        ->toBe('Mon, 9:00 AM – 12:00 PM, 2:00 PM – 6:00 PM');
});

// ---------------------------------------------------------------------------
// Panel
// ---------------------------------------------------------------------------

it('opens the panel from a card with read-only weekly and date-specific tabs', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    apWindow($salon, $stylist->id, 0, 540, 720);
    apWindow($salon, $stylist->id, 0, 840, 1020); // Monday split shift
    TimeOff::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'note' => 'Dentist trip',
        'starts_at' => CarbonImmutable::parse('2027-01-05 10:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2027-01-05 12:00', $salon->timezone),
    ]);

    test()->actingAs($owner);

    $component = Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->assertSet('panelOpen', true)
        ->assertSet('selectedStylistId', $stylist->id)
        ->assertSet('editing', false)
        ->assertSet('panelTab', 'hours')
        // Read view: the split shift and the off days, no editor inputs.
        ->assertSee('Weekly hours')
        ->assertSee('Date-specific hours')
        ->assertSee('9:00 AM – 12:00 PM, 2:00 PM – 5:00 PM')
        ->assertSee('Day off')
        ->assertDontSeeHtml('wire:click="saveHours"');

    // The date-specific tab lists the time off.
    $component->set('panelTab', 'dates')
        ->assertSee('Vacation')
        ->assertSee('Dentist trip');

    // Escape/close resets the panel.
    $component->call('closePanel')->assertSet('panelOpen', false);
});

it('shows the Edit action only to those allowed to edit that stylist', function () {
    $salon = Salon::factory()->create();
    $a = stylistOf($salon);
    $b = stylistOf($salon);

    // A stylist on their own card: may edit.
    test()->actingAs($a);
    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $a->id)
        ->assertSeeHtml('wire:click="startEditing"')
        ->call('startEditing')
        ->assertSet('editing', true)
        ->assertSeeHtml('wire:click="saveHours"');

    // The same stylist on a colleague's card: read-only, no Edit, 403 forged.
    $component = Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $b->id)
        ->assertDontSeeHtml('wire:click="startEditing"')
        ->assertSee('Read-only');
    $component->call('startEditing')->assertForbidden()->assertSet('editing', false);

    // An owner edits anyone.
    test()->actingAs(salonOwnerOf($salon));
    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $b->id)
        ->call('startEditing')
        ->assertSet('editing', true);
});

it('saves hours and time off from the panel and still queues the GHL availability sync', function () {
    $salon = Salon::factory()->create(['timezone' => 'America/New_York']);
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_page', 'private_integration_token' => 'pit-secret', 'calendar_id' => 'cal_m',
    ]);
    $stylist = stylistOf($salon);
    StylistProfile::updateOrCreate(
        ['salon_id' => $salon->id, 'user_id' => $stylist->id],
        ['ghl_user_id' => 'prov_page'],
    );

    Bus::fake([SyncAvailabilityToGhl::class]);
    test()->actingAs($stylist);

    $component = Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->call('startEditing')
        ->set('days.0.on', true)
        ->set('days.0.windows', [['start' => '09:00', 'end' => '17:00']])
        ->call('saveHours')
        ->assertSet('editing', false); // back to the read view after saving

    expect(Availability::where('salon_id', $salon->id)->where('user_id', $stylist->id)->where('kind', 'work')->count())->toBe(1);
    Bus::assertDispatched(SyncAvailabilityToGhl::class, 1);

    // Time off through the panel persists and syncs too.
    $component->set('panelTab', 'dates')
        ->call('startEditing')
        ->set('toStart', '2027-01-05 10:00')
        ->set('toEnd', '2027-01-05 12:00')
        ->call('addTimeOff');

    expect(TimeOff::where('salon_id', $salon->id)->where('user_id', $stylist->id)->count())->toBe(1);
    Bus::assertDispatched(SyncAvailabilityToGhl::class, 2);
});

it('rejects opening a panel for a non-stylist or another salon\'s stylist', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $foreign = stylistOf($salonB);
    $frontDesk = frontDeskOf($salonA);

    test()->actingAs(salonOwnerOf($salonA));

    // Fresh instances per attempt: an aborted call ends that test component.
    Livewire::test('pages::salon.availability.index', ['salon' => $salonA])
        ->call('openPanel', $foreign->id)->assertNotFound()->assertSet('panelOpen', false);
    Livewire::test('pages::salon.availability.index', ['salon' => $salonA])
        ->call('openPanel', $frontDesk->id)->assertNotFound()->assertSet('panelOpen', false);
});

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

    // The date-specific tab lists the time off (note shown; no type label).
    $component->set('panelTab', 'dates')
        ->assertSee('Dentist trip')
        ->assertDontSee('Vacation');

    // Escape/close resets the panel.
    $component->call('closePanel')->assertSet('panelOpen', false);
});

it('renders the schedule as a right-docked drawer teleported to the body', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    test()->actingAs(salonOwnerOf($salon));

    $component = Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        // Out of the page flow entirely: teleported to <body>, pinned to the
        // right edge, drawer width on desktop (full-width only when narrow).
        ->assertSeeHtml('x-teleport="body"')
        ->assertSeeHtml('justify-end')
        ->assertSeeHtml('sm:w-[460px]')
        ->assertSeeHtml('bts-drawer')
        // The focus-return anchor for closing.
        ->assertSeeHtml('id="availability-card-'.$stylist->id.'"');

    // Closing removes the drawer and hands focus back to the card.
    $component->call('closePanel')
        ->assertSet('panelOpen', false)
        ->assertDontSeeHtml('x-teleport="body"')
        ->assertDispatched('availability-panel-closed', stylistId: $stylist->id);
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

it('lays each edit-mode day out as one row: checkbox, side-by-side times, action icons', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    apWindow($salon, $stylist->id, 0, 540, 720);
    apWindow($salon, $stylist->id, 0, 840, 1020); // Monday split shift

    test()->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->call('startEditing')
        // Checkbox day toggles (not switches) + short day names.
        ->assertSeeHtml('type="checkbox"')
        ->assertSee('Mon')
        // Side-by-side labelled time fields.
        ->assertSee('Start time')
        ->assertSee('End time')
        // The split shift renders a second aligned block on the same day.
        ->assertSeeHtml('wire:model="days.0.windows.1.start"')
        // The per-row action icons: add block, duplicate to all days, remove.
        ->assertSeeHtml('wire:click="addWindow(0)"')
        ->assertSeeHtml('wire:click="openCopyPopover(0)"')
        ->assertSeeHtml('wire:click="removeWindow(0, 1)"')
        // An unchecked day reads "Unavailable" with no time fields.
        ->assertSee('Unavailable')
        ->assertDontSeeHtml('wire:model="days.6.windows.0.start"');
});

it('opens the copy-times popover with per-day checkboxes, copy to all, and apply', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    apWindow($salon, $stylist->id, 0, 540, 1020);

    test()->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->call('startEditing')
        ->call('openCopyPopover', 0)
        ->assertSet('copySource', 0)
        ->assertSee('Copy times to')
        ->assertSee('Copy to all')
        ->assertSee('Apply')
        // One checkbox per day (Sunday…Saturday) — the source is pre-checked.
        ->assertSeeHtml('wire:model.live="copyTargets.6"')
        ->assertSeeHtml('wire:model.live="copyTargets.3"')
        ->assertSet('copyTargets.0', true)
        // Escape/outside-click path.
        ->call('closeCopyPopover')
        ->assertSet('copySource', null);
});

it('copy to all checks every day, and apply copies the blocks (split shifts too) to checked days only', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    test()->actingAs($stylist);

    $component = Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        // Source: Tuesday split shift. Thursday has its OWN hours already.
        ->set('days.1.on', true)
        ->set('days.1.windows', [['start' => '09:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '18:00']])
        ->set('days.3.on', true)
        ->set('days.3.windows', [['start' => '08:00', 'end' => '13:00']])
        ->call('openCopyPopover', 1);

    // "Copy to all" selects every day.
    $component->set('copyAll', true);
    foreach (range(0, 6) as $weekday) {
        $component->assertSet('copyTargets.'.$weekday, true);
    }

    // Narrow it down: copy only to Wednesday and Saturday.
    $component->set('copyAll', false)
        ->assertSet('copyTargets.2', false) // unchecking all clears the rest
        ->set('copyTargets.2', true)
        ->set('copyTargets.5', true)
        ->call('applyCopy')
        ->assertSet('copySource', null) // popover closed on apply
        // Both blocks landed on the checked days (enabled + overwritten)…
        ->assertSet('days.2.on', true)
        ->assertSet('days.2.windows.0.start', '09:00')
        ->assertSet('days.2.windows.1.start', '14:00')
        ->assertSet('days.5.windows.1.end', '18:00')
        // …the source kept its own times, and unchecked days are untouched.
        ->assertSet('days.1.windows.0.start', '09:00')
        ->assertSet('days.3.windows.0.start', '08:00')
        ->assertSet('days.0.on', false)
        ->call('saveHours');

    // Tue, Wed, Sat: two blocks each; Thu keeps its single block.
    expect(Availability::where('salon_id', $salon->id)->where('user_id', $stylist->id)->where('kind', 'work')->count())->toBe(7);
    expect(Availability::where('salon_id', $salon->id)->where('user_id', $stylist->id)->where('weekday', 3)->count())->toBe(1);
});

it('removing a day\'s only time block turns the day off (trash on a single block)', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    apWindow($salon, $stylist->id, 0, 540, 1020);

    test()->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->call('startEditing')
        ->call('removeWindow', 0, 0)
        ->assertSet('days.0.on', false)
        ->call('saveHours');

    expect(Availability::where('salon_id', $salon->id)->where('user_id', $stylist->id)->where('kind', 'work')->count())->toBe(0);
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

    // Date-specific hours through the panel persist and sync too.
    $component->set('panelTab', 'dates')
        ->call('startEditing')
        ->call('openDateSpecific')
        ->call('dsToggleDate', '2027-01-05')
        ->set('dsBlocks', [['start' => '10:00', 'end' => '12:00']])
        ->call('dsSubmit')
        ->assertSet('dsModalOpen', false);

    expect(TimeOff::where('salon_id', $salon->id)->where('user_id', $stylist->id)->count())->toBe(1);
    Bus::assertDispatched(SyncAvailabilityToGhl::class, 2);
});

it('shows date-specific entries read-only with a timezone header and no controls', function () {
    $salon = Salon::factory()->create(['timezone' => 'America/New_York']);
    $stylist = stylistOf($salon);
    TimeOff::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2027-07-20 00:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2027-07-21 00:00', $salon->timezone),
    ]);

    test()->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->set('panelTab', 'dates')
        // Timezone header + date + range, reference-style.
        ->assertSee('America/New_York')
        ->assertSee('GMT')
        ->assertSee('July 20, 2027')
        ->assertSee('Unavailable all day')
        // Read mode carries NO edit or delete controls.
        ->assertDontSeeHtml('wire:click="editDateSpecific')
        ->assertDontSeeHtml('wire:click="removeTimeOff');
});

it('adds date-specific hours for several dates at once through the calendar modal', function () {
    $salon = Salon::factory()->create(['timezone' => 'America/New_York']);
    $stylist = stylistOf($salon);

    test()->actingAs($stylist);

    $component = Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->call('startEditing')
        ->set('panelTab', 'dates')
        ->assertSee('No date-specific time added.')
        ->call('openDateSpecific')
        ->assertSet('dsModalOpen', true)
        ->assertSee('Choose the date to set specific hours');

    // Past dates stay grey: toggling one is refused server-side.
    $yesterday = now($salon->timezone)->subDay()->toDateString();
    $component->call('dsToggleDate', $yesterday)->assertSet('dsDates', []);

    // The month navigation never goes before the current month.
    $component->call('dsPrevMonth');
    expect($component->get('dsMonth'))->toBe(now($salon->timezone)->format('Y-m'));

    // Two future dates, one AVAILABLE block — every selected date gets it.
    // The simplified modal has no all-day checkbox, type, or note fields.
    $component->call('dsToggleDate', '2027-01-05')
        ->call('dsToggleDate', '2027-01-07')
        ->assertSee('When are you available?')
        ->assertDontSee('Unavailable all day')
        ->assertDontSee('Note (optional)')
        ->assertDontSeeHtml('wire:model="dsType"')
        ->set('dsBlocks', [['start' => '10:00', 'end' => '12:00']])
        ->call('dsSubmit')
        ->assertSet('dsModalOpen', false)
        ->assertSee('January 5, 2027')
        ->assertSee('January 7, 2027');

    $rows = TimeOff::where('salon_id', $salon->id)->where('user_id', $stylist->id)->orderBy('starts_at')->get();
    expect($rows)->toHaveCount(2);
    expect($rows[0]->kind)->toBe(TimeOff::KIND_HOURS); // the date's AVAILABLE hours
    // 10:00 in January ET is 15:00 UTC — stored DST-safe in the salon zone.
    expect($rows[0]->starts_at->toIso8601String())->toBe('2027-01-05T15:00:00+00:00');
    expect($rows[0]->ends_at->toIso8601String())->toBe('2027-01-05T17:00:00+00:00');
    expect($rows[1]->starts_at->toDateString())->toBe('2027-01-07');

    // Removing every block means "unavailable that date": a full-day OFF row.
    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->call('startEditing')
        ->set('panelTab', 'dates')
        ->call('openDateSpecific')
        ->call('dsToggleDate', '2027-02-01')
        ->call('dsRemoveBlock', 0)
        ->assertSee('No hours — unavailable on the selected date(s).')
        ->call('dsSubmit');

    $offRow = TimeOff::where('salon_id', $salon->id)->where('user_id', $stylist->id)->orderByDesc('starts_at')->first();
    expect($offRow->kind)->toBe(TimeOff::KIND_OFF);
    expect($offRow->starts_at->setTimezone($salon->timezone)->format('Y-m-d H:i'))->toBe('2027-02-01 00:00');
    expect($offRow->ends_at->setTimezone($salon->timezone)->format('Y-m-d H:i'))->toBe('2027-02-02 00:00');
});

it('edits a date-specific entry through the pencil and deletes through the trash', function () {
    $salon = Salon::factory()->create(['timezone' => 'America/New_York']);
    $stylist = stylistOf($salon);
    $off = TimeOff::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'kind' => TimeOff::KIND_HOURS,
        'starts_at' => CarbonImmutable::parse('2027-01-05 10:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2027-01-05 12:00', $salon->timezone),
    ]);

    test()->actingAs($stylist);

    $component = Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->call('startEditing')
        ->set('panelTab', 'dates')
        // Pencil prefills the modal from the entry's available hours.
        ->call('editDateSpecific', $off->id)
        ->assertSet('dsModalOpen', true)
        ->assertSet('dsDates', ['2027-01-05'])
        ->assertSet('dsBlocks.0.start', '10:00')
        // Change the hours and save: the entry is replaced, not duplicated.
        ->set('dsBlocks', [['start' => '14:00', 'end' => '16:00']])
        ->call('dsSubmit');

    $rows = TimeOff::where('salon_id', $salon->id)->where('user_id', $stylist->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->id)->not->toBe($off->id);
    expect($rows[0]->kind)->toBe(TimeOff::KIND_HOURS);
    expect($rows[0]->starts_at->setTimezone($salon->timezone)->format('H:i'))->toBe('14:00');

    // Trash removes it.
    $component->call('removeTimeOff', $rows[0]->id);
    expect(TimeOff::where('salon_id', $salon->id)->count())->toBe(0);
});

it('saves the dates tab from the drawer button and queues the GHL sync, like weekly hours', function () {
    $salon = Salon::factory()->create(['timezone' => 'America/New_York']);
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_ds', 'private_integration_token' => 'pit-secret', 'calendar_id' => 'cal_m',
    ]);
    $stylist = stylistOf($salon);
    StylistProfile::updateOrCreate(
        ['salon_id' => $salon->id, 'user_id' => $stylist->id],
        ['ghl_user_id' => 'prov_ds'],
    );

    Bus::fake([SyncAvailabilityToGhl::class]);
    test()->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->call('startEditing')
        ->set('panelTab', 'dates')
        ->assertSeeHtml('wire:click="saveDateSpecific"')
        ->call('saveDateSpecific')
        ->assertSet('editing', false);

    Bus::assertDispatched(SyncAvailabilityToGhl::class, 1);
});

it('keeps the date-specific modal away from read-only viewers', function () {
    $salon = Salon::factory()->create();
    $a = stylistOf($salon);
    $b = stylistOf($salon);

    test()->actingAs($a);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $b->id)
        ->call('openDateSpecific')
        ->assertForbidden()
        ->assertSet('dsModalOpen', false);
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

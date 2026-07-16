<?php

use App\Actions\Availability\AddTimeOff;
use App\Actions\Availability\SaveWeeklyHours;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Services\Booking\SlotEngine;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** One weekly-hours payload for SaveWeeklyHours — the live grid's write path. */
function weekOf(int $weekday = 0, int $start = 540, int $end = 1020): array
{
    return [$weekday => [['start_minute' => $start, 'end_minute' => $end]]];
}

it('lets a stylist save their own availability', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    app(SaveWeeklyHours::class)->handle($stylist, $salon, $stylist->id, weekOf());

    $window = Availability::query()->where('user_id', $stylist->id)->sole();
    expect($window->salon_id)->toBe($salon->id);
});

it('forbids a stylist editing another stylist\'s availability', function () {
    $salon = Salon::factory()->create();
    $a = stylistOf($salon);
    $b = stylistOf($salon);

    expect(fn () => app(SaveWeeklyHours::class)->handle($a, $salon, $b->id, weekOf()))
        ->toThrow(AuthorizationException::class);
});

it('lets an owner edit any stylist\'s availability', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);

    app(SaveWeeklyHours::class)->handle($owner, $salon, $stylist->id, weekOf());
    expect(Availability::query()->where('user_id', $stylist->id)->exists())->toBeTrue();
});

it('lets front desk — a salon admin since the remap — manage any stylist\'s availability', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $frontDesk = frontDeskOf($salon);

    $this->actingAs($frontDesk)->get(route('salon.availability', $salon))->assertOk();

    // Full admin surface: front desk edits any STYLIST's hours…
    app(SaveWeeklyHours::class)->handle($frontDesk, $salon, $stylist->id, weekOf());
    expect(Availability::query()->where('user_id', $stylist->id)->exists())->toBeTrue();

    // …but is not bookable themselves: no availability of their own.
    expect(fn () => app(SaveWeeklyHours::class)->handle($frontDesk, $salon, $frontDesk->id, weekOf()))
        ->toThrow(ValidationException::class);
});

it('validates positive duration and rejects overlaps', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    // end <= start
    expect(fn () => app(SaveWeeklyHours::class)->handle($stylist, $salon, $stylist->id, weekOf(0, 600, 600)))
        ->toThrow(ValidationException::class);

    // overlapping windows within one day
    expect(fn () => app(SaveWeeklyHours::class)->handle($stylist, $salon, $stylist->id, [
        1 => [['start_minute' => 540, 'end_minute' => 1020], ['start_minute' => 600, 'end_minute' => 700]],
    ]))->toThrow(ValidationException::class);

    // a non-overlapping split shift on the same day is fine
    app(SaveWeeklyHours::class)->handle($stylist, $salon, $stylist->id, [
        1 => [['start_minute' => 540, 'end_minute' => 1020], ['start_minute' => 1020, 'end_minute' => 1140]],
    ]);
    expect(Availability::query()->where('user_id', $stylist->id)->count())->toBe(2);
});

it('stores a time-off override and validates its range', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    $off = app(AddTimeOff::class)->handle($stylist, $salon, $stylist->id, [
        'starts_at' => now()->addDay()->toDateTimeString(),
        'ends_at' => now()->addDays(3)->toDateTimeString(),
    ]);

    expect($off->kind)->toBe(TimeOff::KIND_OFF);
    expect(TimeOff::query()->where('salon_id', $salon->id)->count())->toBe(1);

    // end before start is rejected
    expect(fn () => app(AddTimeOff::class)->handle($stylist, $salon, $stylist->id, [
        'starts_at' => now()->addDays(2)->toDateTimeString(),
        'ends_at' => now()->addDay()->toDateTimeString(),
    ]))->toThrow(ValidationException::class);
});

it('forbids touching another salon\'s stylist hours (anti-IDOR)', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $stylistA = stylistOf($salonA);
    $windowA = Availability::factory()->create(['salon_id' => $salonA->id, 'user_id' => $stylistA->id]);

    // A forged foreign stylist id gets rejected before any write…
    expect(fn () => app(SaveWeeklyHours::class)->handle(salonOwnerOf($salonB), $salonB, $stylistA->id, weekOf()))
        ->toThrow(ValidationException::class);

    // …and salon A's window is untouched.
    expect(Availability::query()->whereKey($windowA->id)->exists())->toBeTrue();
});

it('lets a stylist VIEW a colleague but never edit them from the screen', function () {
    $salon = Salon::factory()->create();
    $a = stylistOf($salon);
    $b = stylistOf($salon);

    $this->actingAs($a);

    // Viewing another stylist's card/panel is fine now…
    $component = Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->assertSet('selectedStylistId', $a->id) // own schedule preloaded
        ->call('openPanel', $b->id)
        ->assertSet('selectedStylistId', $b->id)
        ->assertSet('editing', false);

    // …but edit mode is server-gated, and a forged save is rejected too
    // (fresh instances: an aborted call ends that test component; Livewire
    // renders the action's AuthorizationException as a 403 response).
    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $b->id)
        ->set('days.0.on', true)
        ->set('days.0.windows', [['start' => '09:00', 'end' => '17:00']])
        ->call('saveHours')
        ->assertForbidden();
    $component->call('startEditing')->assertForbidden()->assertSet('editing', false);
    expect(Availability::where('salon_id', $salon->id)->where('user_id', $b->id)->count())->toBe(0);
});

it('renders the availability screen for a stylist and a manager', function () {
    $salon = Salon::factory()->create();

    $this->actingAs(stylistOf($salon))->get(route('salon.availability', $salon))->assertOk();
    $this->actingAs(salonOwnerOf($salon))->get(route('salon.availability', $salon))->assertOk();
});

it('loads stored work windows into the weekly grid as toggled-on rows with times', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    Availability::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'weekday' => 0, 'kind' => 'work', 'start_minute' => 540, 'end_minute' => 1020,
    ]);

    $this->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->call('openPanel', $stylist->id)
        ->assertSet('days.0.on', true)
        ->assertSet('days.0.windows.0.start', '09:00')
        ->assertSet('days.0.windows.0.end', '17:00')
        ->assertSet('days.1.on', false); // a day with no window reads as off

    // The data model is unchanged — still a work-kind Availability row.
    expect(Availability::where('salon_id', $salon->id)->where('kind', 'work')->count())->toBe(1);
});

it('saves a day\'s hours entered in the grid as a single work window', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $this->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->set('days.0.on', true)
        ->set('days.0.windows', [['start' => '09:00', 'end' => '17:00']])
        ->call('saveHours');

    $windows = Availability::where('salon_id', $salon->id)->where('user_id', $stylist->id)->where('kind', 'work')->get();
    expect($windows)->toHaveCount(1);
    expect($windows->first()->weekday)->toBe(0);
    expect($windows->first()->start_minute)->toBe(540);
    expect($windows->first()->end_minute)->toBe(1020);
});

it('removes a day\'s work windows when toggled off and saved', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    Availability::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'weekday' => 0, 'kind' => 'work', 'start_minute' => 540, 'end_minute' => 1020,
    ]);
    $this->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->assertSet('days.0.on', true)
        ->call('toggleDay', 0)
        ->assertSet('days.0.on', false)
        ->call('saveHours');

    expect(Availability::where('salon_id', $salon->id)->where('user_id', $stylist->id)->where('kind', 'work')->count())->toBe(0);
});

it('saves a split shift as two work windows with a gap', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $this->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->set('days.1.on', true)
        ->set('days.1.windows', [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']])
        ->call('saveHours');

    $windows = Availability::where('salon_id', $salon->id)->where('user_id', $stylist->id)
        ->where('kind', 'work')->where('weekday', 1)->orderBy('start_minute')->get();

    expect($windows)->toHaveCount(2);
    expect([$windows[0]->start_minute, $windows[0]->end_minute])->toBe([540, 720]);
    expect([$windows[1]->start_minute, $windows[1]->end_minute])->toBe([780, 1020]);
});

it('rejects overlapping windows within a day on save', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $this->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->set('days.0.on', true)
        ->set('days.0.windows', [['start' => '09:00', 'end' => '13:00'], ['start' => '12:00', 'end' => '17:00']])
        ->call('saveHours')
        ->assertHasErrors('weekly');

    expect(Availability::where('salon_id', $salon->id)->where('kind', 'work')->count())->toBe(0);
});

it('lets date-specific HOURS replace the weekly schedule in the slot engine', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    // Weekly: Monday 09:00–17:00 only.
    Availability::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'weekday' => 0, 'kind' => 'work', 'start_minute' => 540, 'end_minute' => 1020,
    ]);

    $engine = app(SlotEngine::class);
    $monday = CarbonImmutable::now($salon->timezone)->next(CarbonImmutable::MONDAY)->startOfDay();
    $sunday = $monday->addDays(6);

    // Override that Monday to 10:00–14:00: 10:00 bookable, 09:00 no longer.
    TimeOff::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'kind' => TimeOff::KIND_HOURS,
        'starts_at' => $monday->setTime(10, 0), 'ends_at' => $monday->setTime(14, 0),
    ]);

    expect($engine->isAvailable($salon, $stylist->id, $monday->setTime(10, 0), 60))->toBeTrue();
    expect($engine->isAvailable($salon, $stylist->id, $monday->setTime(9, 0), 60))->toBeFalse();

    // An override even opens a weekly day OFF (Sunday has no weekly hours).
    TimeOff::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'kind' => TimeOff::KIND_HOURS,
        'starts_at' => $sunday->setTime(11, 0), 'ends_at' => $sunday->setTime(15, 0),
    ]);

    expect($engine->isAvailable($salon, $stylist->id, $sunday->setTime(11, 0), 60))->toBeTrue();
    expect($engine->slotsFor($salon, $stylist->id, 60, $sunday))->not->toBeEmpty();
});

it('copies Monday hours to the weekdays only through the popover', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $this->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->set('days.0.on', true)
        ->set('days.0.windows', [['start' => '09:00', 'end' => '17:00']])
        ->call('openCopyPopover', 0)
        ->set('copyTargets.1', true)
        ->set('copyTargets.2', true)
        ->set('copyTargets.3', true)
        ->set('copyTargets.4', true)
        ->call('applyCopy')
        ->assertSet('days.4.on', true)
        ->assertSet('days.4.windows.0.start', '09:00')
        ->assertSet('days.5.on', false) // Saturday untouched
        ->call('saveHours');

    // Mon–Fri (0–4) each get one window; Sat/Sun get none.
    expect(Availability::where('salon_id', $salon->id)->where('kind', 'work')->count())->toBe(5);
    expect(Availability::where('salon_id', $salon->id)->where('kind', 'work')->where('weekday', 5)->count())->toBe(0);
});

it('copies Monday hours to every day through copy-to-all', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $this->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->set('days.0.on', true)
        ->set('days.0.windows', [['start' => '10:00', 'end' => '16:00']])
        ->call('openCopyPopover', 0)
        ->set('copyAll', true)
        ->call('applyCopy')
        ->assertSet('days.6.on', true)
        ->call('saveHours');

    expect(Availability::where('salon_id', $salon->id)->where('kind', 'work')->count())->toBe(7);
});

it('lets the slot engine read hours entered through the grid (regression)', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $this->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->set('days.0.on', true)               // weekday 0 = Monday
        ->set('days.0.windows', [['start' => '09:00', 'end' => '17:00']])
        ->call('saveHours');

    $engine = app(SlotEngine::class);
    // The upcoming Monday, so the asserted slots are always in the future and
    // never rejected by the booking policy as past.
    $monday = CarbonImmutable::now($salon->timezone)->next(CarbonImmutable::MONDAY)->setTime(9, 0);

    // Inside the grid-entered window: bookable. Before it: not.
    expect($engine->isAvailable($salon, $stylist->id, $monday, 60))->toBeTrue();
    expect($engine->isAvailable($salon, $stylist->id, $monday->setTime(8, 0), 60))->toBeFalse();
    expect($engine->slotsFor($salon, $stylist->id, 60, $monday->toDateString()))->not->toBeEmpty();
});

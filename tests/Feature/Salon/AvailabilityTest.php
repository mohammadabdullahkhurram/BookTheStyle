<?php

use App\Actions\Availability\AddAvailabilityWindow;
use App\Actions\Availability\AddTimeOff;
use App\Actions\Availability\RemoveAvailabilityWindow;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Services\Booking\SlotEngine;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function workWindow(int $weekday = 0, int $start = 540, int $end = 1020): array
{
    return ['weekday' => $weekday, 'kind' => 'work', 'start_minute' => $start, 'end_minute' => $end];
}

it('lets a stylist add their own availability', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    $window = app(AddAvailabilityWindow::class)->handle($stylist, $salon, $stylist->id, workWindow());

    expect($window->user_id)->toBe($stylist->id);
    expect($window->salon_id)->toBe($salon->id);
});

it('forbids a stylist editing another stylist\'s availability', function () {
    $salon = Salon::factory()->create();
    $a = stylistOf($salon);
    $b = stylistOf($salon);

    expect(fn () => app(AddAvailabilityWindow::class)->handle($a, $salon, $b->id, workWindow()))
        ->toThrow(AuthorizationException::class);
});

it('lets an owner edit any stylist\'s availability', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);

    $window = app(AddAvailabilityWindow::class)->handle($owner, $salon, $stylist->id, workWindow());
    expect($window->user_id)->toBe($stylist->id);
});

it('forbids front desk from availability (screen + action)', function () {
    $salon = Salon::factory()->create();
    $frontDesk = frontDeskOf($salon);

    $this->actingAs($frontDesk)->get(route('salon.availability', $salon))->assertForbidden();

    expect(fn () => app(AddAvailabilityWindow::class)->handle($frontDesk, $salon, $frontDesk->id, workWindow()))
        ->toThrow(AuthorizationException::class);
});

it('validates positive duration and rejects overlaps', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    // end <= start
    expect(fn () => app(AddAvailabilityWindow::class)->handle($stylist, $salon, $stylist->id, workWindow(0, 600, 600)))
        ->toThrow(ValidationException::class);

    // overlapping same-kind window on the same day
    app(AddAvailabilityWindow::class)->handle($stylist, $salon, $stylist->id, workWindow(1, 540, 1020));
    expect(fn () => app(AddAvailabilityWindow::class)->handle($stylist, $salon, $stylist->id, workWindow(1, 600, 700)))
        ->toThrow(ValidationException::class);

    // a non-overlapping split shift on the same day is fine
    $second = app(AddAvailabilityWindow::class)->handle($stylist, $salon, $stylist->id, workWindow(1, 1020, 1140));
    expect($second)->not->toBeNull();
});

it('stores a time-off override and validates its range', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    $off = app(AddTimeOff::class)->handle($stylist, $salon, $stylist->id, [
        'type' => 'vacation',
        'starts_at' => now()->addDay()->toDateTimeString(),
        'ends_at' => now()->addDays(3)->toDateTimeString(),
    ]);

    expect($off->type->value)->toBe('vacation');
    expect(TimeOff::query()->where('salon_id', $salon->id)->count())->toBe(1);

    // end before start is rejected
    expect(fn () => app(AddTimeOff::class)->handle($stylist, $salon, $stylist->id, [
        'type' => 'sick',
        'starts_at' => now()->addDays(2)->toDateTimeString(),
        'ends_at' => now()->addDay()->toDateTimeString(),
    ]))->toThrow(ValidationException::class);
});

it('forbids removing another salon\'s window (anti-IDOR)', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $stylistA = stylistOf($salonA);
    $windowA = Availability::factory()->create(['salon_id' => $salonA->id, 'user_id' => $stylistA->id]);

    expect(fn () => app(RemoveAvailabilityWindow::class)->handle(salonOwnerOf($salonB), $salonB, $windowA))
        ->toThrow(AuthorizationException::class);
});

it('locks a stylist to their own availability on the screen', function () {
    $salon = Salon::factory()->create();
    $a = stylistOf($salon);
    $b = stylistOf($salon);

    $this->actingAs($a);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->assertSet('selectedStylistId', $a->id)
        // Tampering the selected stylist is forced back to self.
        ->set('selectedStylistId', $b->id)
        ->assertSet('selectedStylistId', $a->id);
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
        ->set('selectedStylistId', $stylist->id)
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

it('copies Monday hours to the weekdays only', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $this->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->set('days.0.on', true)
        ->set('days.0.windows', [['start' => '09:00', 'end' => '17:00']])
        ->call('copyToWeekdays')
        ->assertSet('days.4.on', true)
        ->assertSet('days.4.windows.0.start', '09:00')
        ->assertSet('days.5.on', false) // Saturday untouched
        ->call('saveHours');

    // Mon–Fri (0–4) each get one window; Sat/Sun get none.
    expect(Availability::where('salon_id', $salon->id)->where('kind', 'work')->count())->toBe(5);
    expect(Availability::where('salon_id', $salon->id)->where('kind', 'work')->where('weekday', 5)->count())->toBe(0);
});

it('copies Monday hours to every day', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $this->actingAs($stylist);

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->set('days.0.on', true)
        ->set('days.0.windows', [['start' => '10:00', 'end' => '16:00']])
        ->call('copyToAll')
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
    $monday = CarbonImmutable::parse('2026-06-22 09:00', $salon->timezone); // a Monday

    // Inside the grid-entered window: bookable. Before it: not.
    expect($engine->isAvailable($salon, $stylist->id, $monday, 60))->toBeTrue();
    expect($engine->isAvailable($salon, $stylist->id, $monday->setTime(8, 0), 60))->toBeFalse();
    expect($engine->slotsFor($salon, $stylist->id, 60, '2026-06-22'))->not->toBeEmpty();
});

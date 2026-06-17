<?php

use App\Actions\Availability\AddAvailabilityWindow;
use App\Actions\Availability\AddTimeOff;
use App\Actions\Availability\RemoveAvailabilityWindow;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\TimeOff;
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

it('renders the weekly grid with windows formatted as times', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    Availability::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'weekday' => 0, 'kind' => 'work', 'start_minute' => 540, 'end_minute' => 1020,
    ]);

    $this->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.availability.index', ['salon' => $salon])
        ->set('selectedStylistId', $stylist->id)
        ->assertSee('09:00')
        ->assertSee('17:00');
});

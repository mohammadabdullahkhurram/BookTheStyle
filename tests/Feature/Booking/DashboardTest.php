<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'))); // Mon 08:00 EDT
afterEach(fn () => Carbon::setTestNow());

it('shows today\'s bookings + stats to a manager', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);
    makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00', 'Dana Diner');

    $this->actingAs($owner);
    Livewire::test('pages::salon.dashboard', ['salon' => $salon])
        ->assertSee('Dana Diner')
        ->assertSee('Total')
        ->assertSee('Per-stylist load');
});

it('shows a stylist only their own bookings on the dashboard', function () {
    $salon = bookingSalon();
    $stylistA = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $serviceA = serviceFor($salon, $stylistA, 60);
    $serviceB = serviceFor($salon, $stylistB, 60);
    $owner = salonOwnerOf($salon);

    makeBooking($salon, $owner, $stylistA, $serviceA, '2026-06-22 10:00', 'Alice Anderson');
    makeBooking($salon, $owner, $stylistB, $serviceB, '2026-06-22 11:00', 'Bob Brown');

    $this->actingAs($stylistA);
    Livewire::test('pages::salon.dashboard', ['salon' => $salon])
        ->assertSee('Alice Anderson')
        ->assertDontSee('Bob Brown');
});

it('lets any member open the dashboard but blocks non-members', function () {
    $salon = bookingSalon();

    $this->actingAs(stylistOf($salon))->get(route('salon.show', $salon))->assertOk();
    $this->actingAs(salonOwnerOf($salon))->get(route('salon.show', $salon))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('salon.show', $salon))->assertForbidden();
});

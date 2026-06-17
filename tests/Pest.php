<?php

use App\Actions\Bookings\CreateBooking;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
| Shared salon-role helpers used across feature tests.
*/

function salonOwnerOf(Salon $salon): User
{
    $user = User::factory()->create();
    SalonMembership::factory()->for($user)->for($salon)->owner()->create();

    return $user;
}

function salonAdminOf(Salon $salon): User
{
    $user = User::factory()->create();
    SalonMembership::factory()->for($user)->for($salon)->admin()->create();

    return $user;
}

function stylistOf(Salon $salon, ?User $user = null): User
{
    $user ??= User::factory()->create();
    SalonMembership::factory()->for($user)->for($salon)->stylist()->create();

    return $user;
}

function frontDeskOf(Salon $salon): User
{
    $user = User::factory()->create();
    SalonMembership::factory()->for($user)->for($salon)->frontDesk()->create();

    return $user;
}

/**
 * A salon with America/New_York timezone and lenient booking policy, used by the
 * slot-engine and booking tests (override the policy as needed).
 *
 * @param  array<string, mixed>  $overrides
 */
function bookingSalon(array $overrides = []): Salon
{
    return Salon::factory()->create(array_merge([
        'timezone' => 'America/New_York',
        'allow_walkins' => true,
        'allow_same_day' => true,
        'max_advance_days' => 90,
        'min_notice_minutes' => 0,
    ], $overrides));
}

function stylistWithHours(Salon $salon, int $weekday, int $startMin, int $endMin, ?User $stylist = null): User
{
    $stylist ??= stylistOf($salon);
    Availability::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'weekday' => $weekday, 'kind' => 'work',
        'start_minute' => $startMin, 'end_minute' => $endMin,
    ]);

    return $stylist;
}

function serviceFor(Salon $salon, User $stylist, int $duration = 60): Service
{
    $service = Service::factory()->create(['salon_id' => $salon->id, 'duration_min' => $duration]);
    $service->stylists()->attach($stylist->id, ['salon_id' => $salon->id]);

    return $service;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bookingData(array $overrides = []): array
{
    return array_merge([
        'client' => ['name' => 'Walk-in Client'],
        'items' => [],
        'start' => '2026-06-22 10:00',
        'is_walkin' => false,
        'notes' => null,
    ], $overrides);
}

function makeBooking(Salon $salon, User $actor, User $stylist, Service $service, string $start = '2026-06-22 10:00', string $clientName = 'Casey Client'): Booking
{
    return app(CreateBooking::class)->handle($actor, $salon, [
        'client' => ['name' => $clientName],
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
        'start' => $start,
        'is_walkin' => false,
        'notes' => null,
    ]);
}

/*
| Whether a browser would send a cookie set with Domain=$cookieDomain to BOTH
| $apexHost and $subHost — i.e. whether the login session is genuinely shared
| across the apex and a salon subdomain. Encodes the two rules that bite local
| dev: a Domain attribute for `localhost`/`*.localhost` is refused by browsers
| (so it can't be shared), and a Domain must domain-match each host. This is the
| check Laravel's test HTTP client does NOT enforce, so we assert it explicitly.
*/
function browserSharesCookie(?string $cookieDomain, string $apexHost, string $subHost): bool
{
    if ($cookieDomain === null || $cookieDomain === '') {
        return false; // host-only cookie — never shared to another host
    }

    $domain = ltrim($cookieDomain, '.');

    // Browsers refuse to set a Domain cookie for localhost / *.localhost.
    if ($domain === 'localhost' || str_ends_with($domain, '.localhost')) {
        return false;
    }

    // Must be a registrable (dotted) domain.
    if (! str_contains($domain, '.')) {
        return false;
    }

    $matches = fn (string $host): bool => $host === $domain || str_ends_with($host, '.'.$domain);

    return $matches($apexHost) && $matches($subHost);
}

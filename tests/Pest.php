<?php

use App\Actions\Bookings\CreateBooking;
use App\Enums\SalonRole;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\Service;
use App\Models\User;
use App\Support\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
| Shared salon-role helpers used across feature tests.
*/

/*
| Shared widget helpers (WidgetBookingTest + ChatWidgetTest): the frozen
| clock both suites use is Mon 2026-06-22 12:00 UTC.
*/

/** @return array{0: Salon, 1: User, 2: Service} */
function widgetSalon(): array
{
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60); // Monday 9–5
    $service = serviceFor($salon, $stylist, 60);
    $service->update(['name' => 'Haircut', 'price_cents' => 4500]);

    return [$salon, $stylist, $service];
}

/** A page token like the widget page embeds, backdated $ageSeconds. */
function widgetToken(Salon $salon, int $ageSeconds = 30): string
{
    return Crypt::encryptString((string) json_encode([
        'salon' => $salon->id,
        'iat' => now()->timestamp - $ageSeconds,
    ]));
}

/** A valid book payload for the salon's Haircut at 2 PM. */
function widgetPayload(Salon $salon, array $overrides = []): array
{
    return array_merge([
        'service' => $salon->services()->firstOrFail()->id,
        'stylist' => 'any',
        'date' => '2026-06-22',
        'time' => '2:00 PM',
        'client' => ['name' => 'Widget Wendy', 'phone' => '+15550301'],
        'token' => widgetToken($salon),
        'website' => '',
    ], $overrides);
}

/** Seed (idempotently) and return THE canonical demo showcase salon. */
function demoShowcase(): Salon
{
    test()->artisan('demo:seed-showcase')->assertExitCode(0);

    return DemoMode::showcaseSalon()
        ?? throw new RuntimeException('demo showcase missing after seeding');
}

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

/**
 * Historic helper: "front desk" was absorbed into the Manager role
 * (functionally identical) in the owner/manager/stylist rework. Kept so the
 * many call sites keep expressing "a desk-running member" — now a Manager.
 */
function frontDeskOf(Salon $salon): User
{
    $user = User::factory()->create();
    SalonMembership::factory()->for($user)->for($salon)->manager()->create();

    return $user;
}

/**
 * A Manager-role member (no bookable function).
 */
function managerOf(Salon $salon, SalonRole $role = SalonRole::Manager): User
{
    $user = User::factory()->create();
    SalonMembership::factory()->for($user)->for($salon)->manager()
        ->state(['salon_role' => $role])->create();

    return $user;
}

/**
 * A complete, valid business + contact profile payload for the salon create
 * form (everything required except website / address_line2). Spread onto a
 * Livewire create test with ->set(salonProfileInput([...])).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function salonProfileInput(array $overrides = []): array
{
    return array_merge([
        'name' => 'Glow Bar',
        'legal_business_name' => 'Glow Bar LLC',
        'business_email' => 'hello@glow-bar.test',
        'business_phone' => '+1 212-555-0000',
        'website' => 'https://glow-bar.test',
        'address_line1' => '1 Test Street',
        'address_line2' => '',
        'city' => 'Testville',
        'region' => 'Test Region',
        'postal_code' => '12345',
        'country' => 'United States',
        'contact_name' => 'Test Contact',
        'contact_email' => 'contact@glow-bar.test',
        'contact_phone' => '+1 212-555-0001',
    ], $overrides);
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
 * The default start (Mon 2026-06-22 10:00) is "later today" only under the
 * booking suites' frozen clock (Carbon::setTestNow, Mon 2026-06-22 12:00 UTC).
 * A test that does not freeze time must pass its own future start, or the
 * booking policy will reject the past date.
 *
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

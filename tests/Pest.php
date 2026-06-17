<?php

use App\Models\Availability;
use App\Models\Salon;
use App\Models\SalonMembership;
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

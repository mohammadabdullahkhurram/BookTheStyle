<?php

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

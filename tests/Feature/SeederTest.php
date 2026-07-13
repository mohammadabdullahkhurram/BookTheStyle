<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
| The dev seed is a clean agency-only slate: one agency (Bluejaypro), its
| agency-level accounts, and zero salon-level data — so an agency login can
| create a brand-new salon from scratch. Tests never depend on the seeder;
| this file pins that contract.
*/

it('seeds only the Bluejaypro agency and its agency-level accounts', function () {
    $this->seed();

    expect(Agency::count())->toBe(1);
    expect(Agency::query()->first()->name)->toBe('Bluejaypro');

    // No salons and nothing under them — every salon-scoped table is empty.
    expect(Salon::count())->toBe(0);
    $salonTables = [
        'salon_memberships', 'stylist_profiles', 'services', 'service_stylist',
        'availabilities', 'time_off', 'bookings', 'booking_items',
        'booking_status_events', 'clients', 'calendar_connections',
        'salon_ghl_connections', 'agency_salon_assignments',
    ];
    foreach ($salonTables as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }

    // Exactly the three agency accounts, able to log in, no forced change.
    expect(User::count())->toBe(3);

    $roles = [
        'agency@bookthestyle.test' => AgencyRole::Owner,
        'admin@bookthestyle.test' => AgencyRole::Admin,
        'user@bookthestyle.test' => AgencyRole::User,
    ];
    foreach ($roles as $email => $role) {
        $user = User::where('email', $email)->first();
        expect($user)->not->toBeNull();
        expect($user->agency_role)->toBe($role);
        expect(Hash::check('password', $user->password))->toBeTrue();
        expect($user->must_change_password)->toBeFalse();
    }
});

it('shows the seeded agency owner an empty salon picker with the console entry', function () {
    $this->seed();
    $owner = User::where('email', 'agency@bookthestyle.test')->firstOrFail();

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Agency dashboard')
        ->assertSee('No salons yet');
});

it('is idempotent across repeated runs', function () {
    $this->seed();
    $this->seed();

    expect(Agency::count())->toBe(1);
    expect(User::count())->toBe(3);
    expect(Salon::count())->toBe(0);
});

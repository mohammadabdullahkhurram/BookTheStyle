<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use Database\Seeders\DemoSalonSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
| The factory reset: a DATA-ONLY wipe (schema untouched — never
| migrate:fresh, CLAUDE.md rule 10) that leaves exactly one agency owner
| (abdullah@bluejaypro.com) on the Bluejaypro agency and nothing else.
*/

it('resets to pristine: one agency owner, zero everything else, schema intact', function () {
    // A fully-populated app: the demo salon (staff, services, availability,
    // clients, ~50 bookings, widget) plus the base agency accounts.
    $this->seed();
    $this->seed(DemoSalonSeeder::class);
    expect(Salon::count())->toBeGreaterThan(0);
    expect(User::count())->toBeGreaterThan(4);

    Artisan::call('app:factory-reset', ['--force' => true]);

    // Exactly one user: the agency owner, with the printed password working.
    expect(User::count())->toBe(1);
    $owner = User::query()->sole();
    expect($owner->email)->toBe('abdullah@bluejaypro.com');
    expect($owner->agency_role)->toBe(AgencyRole::Owner);
    expect($owner->must_change_password)->toBeFalse();
    expect(Hash::check('password', $owner->password))->toBeTrue();

    // One agency, ZERO of everything else.
    expect(Agency::count())->toBe(1);
    foreach ([
        'salons', 'salon_memberships', 'agency_salon_assignments',
        'services', 'service_stylist', 'stylist_profiles',
        'availabilities', 'time_off',
        'clients', 'client_notes',
        'bookings', 'booking_items', 'booking_status_events',
        'widgets', 'salon_ghl_connections', 'webhook_events', 'calendar_connections',
        'sessions', 'password_reset_tokens', 'jobs', 'failed_jobs',
    ] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }

    // Schema untouched: the tables all still exist (data-only reset).
    foreach (['salons', 'bookings', 'widgets', 'clients', 'migrations'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    // The printed credentials appear in the command output.
    expect(Artisan::output())->toContain('abdullah@bluejaypro.com');

    // Logging in works and lands in the agency console with zero salons.
    $this->post(route('login.store'), ['email' => 'abdullah@bluejaypro.com', 'password' => 'password'])
        ->assertRedirect();
    $this->actingAs($owner->fresh())->get(route('agency.overview'))
        ->assertOk()
        ->assertSee('No salons yet');
});

it('is repeatable and never runs without explicit confirmation', function () {
    $this->seed(DemoSalonSeeder::class);
    $before = User::count();

    // Declining the prompt touches nothing.
    $this->artisan('app:factory-reset')
        ->expectsConfirmation('This wipes ALL application data (salons, users, bookings, clients, widgets, connections) and leaves one agency owner. Continue?', 'no')
        ->assertSuccessful();
    expect(User::count())->toBe($before);
    expect(Salon::count())->toBeGreaterThan(0);

    // Running twice converges on the same pristine state.
    Artisan::call('app:factory-reset', ['--force' => true]);
    Artisan::call('app:factory-reset', ['--force' => true]);
    expect(User::count())->toBe(1);
    expect(Agency::count())->toBe(1);
    expect(Salon::count())->toBe(0);
});

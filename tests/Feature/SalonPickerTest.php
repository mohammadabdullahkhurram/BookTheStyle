<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| The salon-picker cards: live business details from settings, per-salon role
| badges, at-a-glance stats, and no per-salon (N+1) queries.
*/

it('shows the salon\'s current business details and reflects settings edits live', function () {
    $salon = Salon::factory()->create([
        'name' => 'Glow Bar',
        'city' => 'Brooklyn',
        'region' => 'New York',
        'business_phone' => '+1 212-555-0123',
        'contact_email' => 'hello@glow-bar.test',
    ]);
    $this->actingAs(salonOwnerOf($salon));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Glow Bar')
        ->assertSee('Brooklyn, New York')
        ->assertSee('+1 212-555-0123')
        ->assertSee('hello@glow-bar.test')
        ->assertSee('Owner');

    // A settings save updates the salons row → the picker reflects it next load
    // (it reads live from that row, no stale cache).
    $salon->update(['city' => 'Queens']);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Queens, New York')
        ->assertDontSee('Brooklyn');
});

it('renders at-a-glance stats from eager-loaded counts', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    stylistOf($salon);
    stylistOf($salon);
    Service::factory()->count(3)->for($salon)->create(['active' => true]);
    Service::factory()->for($salon)->create(['active' => false]); // excluded

    $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toMatch('/2<\/span>\s*stylists/');
    expect($html)->toMatch('/3<\/span>\s*services/'); // active only
});

it('shows the correct per-salon role badge for a multi-salon user', function () {
    $user = User::factory()->create();
    $owned = Salon::factory()->create(['name' => 'Aurora Salon']);
    $stylistAt = Salon::factory()->create(['name' => 'Borealis Salon']);

    SalonMembership::factory()->for($user)->for($owned)->owner()->create();
    SalonMembership::factory()->for($user)->for($stylistAt)->stylist()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Aurora Salon')
        ->assertSee('Borealis Salon')
        ->assertSee('Owner')
        ->assertSee('Stylist');
});

it('does not run per-salon queries (no N+1) as the salon count grows', function () {
    $agency = Agency::factory()->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $this->actingAs($owner);

    Salon::factory()->count(2)->for($agency)->create();
    $this->get(route('dashboard'))->assertOk(); // warm up

    DB::enableQueryLog();
    $this->get(route('dashboard'))->assertOk();
    $withTwo = count(DB::getQueryLog());

    Salon::factory()->count(6)->for($agency)->create(); // 8 salons total

    DB::flushQueryLog();
    $this->get(route('dashboard'))->assertOk();
    $withEight = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Tripling the salons must not add queries — the stats/role data are
    // batched (withCount + a single membership lookup), not per card.
    expect($withEight)->toBe($withTwo);
});

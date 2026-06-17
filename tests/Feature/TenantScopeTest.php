<?php

use App\Models\Availability;
use App\Models\Salon;
use App\Models\Service;
use App\Models\StylistProfile;
use App\Models\TimeOff;
use App\Models\User;

/*
| The SalonScope global scope: salon-owned content auto-scopes to the active
| salon (bound as `currentSalon` by ResolveSalon). Auth/tenancy tables stay
| un-scoped.
*/

it('scopes salon-owned models to the active salon', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();

    $serviceA = Service::factory()->create(['salon_id' => $salonA->id]);
    $serviceB = Service::factory()->create(['salon_id' => $salonB->id]);

    // No active salon → unscoped.
    expect(Service::count())->toBe(2);

    // Active salon B → only B's row is visible/queryable; A's is hidden + null.
    app()->instance('currentSalon', $salonB);
    expect(Service::count())->toBe(1);
    expect(Service::pluck('id')->all())->toBe([$serviceB->id]);
    expect(Service::find($serviceA->id))->toBeNull();

    // Switch to A.
    app()->instance('currentSalon', $salonA);
    expect(Service::find($serviceA->id))->not->toBeNull();
    expect(Service::find($serviceB->id))->toBeNull();
});

it('scopes availability, profiles, and time off too', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    Availability::factory()->create(['salon_id' => $salonA->id, 'user_id' => $userA->id]);
    Availability::factory()->create(['salon_id' => $salonB->id, 'user_id' => $userB->id]);
    StylistProfile::factory()->create(['salon_id' => $salonA->id, 'user_id' => $userA->id]);
    StylistProfile::factory()->create(['salon_id' => $salonB->id, 'user_id' => $userB->id]);
    TimeOff::factory()->create(['salon_id' => $salonA->id, 'user_id' => $userA->id]);
    TimeOff::factory()->create(['salon_id' => $salonB->id, 'user_id' => $userB->id]);

    app()->instance('currentSalon', $salonA);
    expect(Availability::count())->toBe(1);
    expect(StylistProfile::count())->toBe(1);
    expect(TimeOff::count())->toBe(1);
});

it('does NOT scope users or memberships', function () {
    $salonA = Salon::factory()->create();
    Salon::factory()->create();
    User::factory()->count(3)->create();

    app()->instance('currentSalon', $salonA);

    // Users have no salon_id and must remain fully queryable (tenancy tables are
    // guarded by ResolveSalon + authorization, not the global scope).
    expect(User::count())->toBe(3);
});

it('auto-fills salon_id from the active salon on create', function () {
    $salon = Salon::factory()->create();
    app()->instance('currentSalon', $salon);

    $service = new Service(['name' => 'Cut', 'duration_min' => 30, 'color' => '#1F6F6B']);
    $service->save();

    expect($service->salon_id)->toBe($salon->id);
});

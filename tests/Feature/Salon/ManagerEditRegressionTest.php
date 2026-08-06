<?php

use App\Actions\Salons\SetSalonOwner;
use App\Actions\Staff\UpdateStaffMembership;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Enums\SalonType;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use App\Support\ProvisionedUser;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Live-regression reproduction: updating an EXISTING manager from the salon
| Users page. Matrix over actors, fields, and tenant context to find the
| exact failing interaction before fixing it.
*/

it('saves an existing manager from the edit modal — owner actor, checkbox toggle, tenant bound', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $membership = managerOf($salon)->membershipFor($salon);

    // Live parity: ResolveSalon binds the tenant on every component request.
    app()->instance('currentSalon', $salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->set('editTakesBookings', true)
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($membership->fresh()->staff_type)->toBe(StaffType::Stylist);

    app()->forgetInstance('currentSalon');
});

it('saves an existing manager keeping everything unchanged — a plain re-save', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $membership = managerOf($salon)->membershipFor($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->call('saveEdit')
        ->assertHasNoErrors();

    $fresh = $membership->fresh();
    expect($fresh->salon_role)->toBe(SalonRole::Manager);
    expect($fresh->staff_type)->toBeNull();
});

it('saves an existing manager as a manager actor editing themselves', function () {
    $salon = Salon::factory()->create();
    salonOwnerOf($salon);
    $manager = managerOf($salon);
    $membership = $manager->membershipFor($salon);

    Livewire::actingAs($manager)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->set('editTakesBookings', true)
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($membership->fresh()->staff_type)->toBe(StaffType::Stylist);
});

it('saves an existing BOOKABLE manager (post-feature data) across salon types', function () {
    foreach (SalonType::cases() as $type) {
        $salon = Salon::factory()->create(['salon_type' => $type]);
        $owner = salonOwnerOf($salon);
        $membership = managerOf($salon)->membershipFor($salon);
        $membership->update(['staff_type' => StaffType::Stylist]);

        Livewire::actingAs($owner)
            ->test('pages::salon.users.index', ['salon' => $salon])
            ->call('startEdit', $membership->id)
            ->assertSet('editTakesBookings', true)
            ->set('editTakesBookings', false)
            ->call('saveEdit')
            ->assertHasNoErrors();

        expect($membership->fresh()->staff_type)->toBeNull();
    }
});

it('saves a manager role change to stylist and back', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $membership = managerOf($salon)->membershipFor($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->set('editRole', 'stylist')
        ->call('saveEdit')
        ->assertHasNoErrors();
    expect($membership->fresh()->salon_role)->toBe(SalonRole::Stylist);

    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->set('editRole', 'salon_manager')
        ->set('editTakesBookings', false)
        ->call('saveEdit')
        ->assertHasNoErrors();
    $fresh = $membership->fresh();
    expect($fresh->salon_role)->toBe(SalonRole::Manager);
    expect($fresh->staff_type)->toBeNull();
});

it('lets an agency operator save a manager, edit their details, and toggle nothing shared', function () {
    $salon = Salon::factory()->create();
    salonOwnerOf($salon);
    $membership = managerOf($salon)->membershipFor($salon);
    $operator = User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Admin,
    ]);

    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->set('editTakesBookings', true)
        ->call('saveEdit')
        ->assertHasNoErrors();
    expect($membership->fresh()->staff_type)->toBe(StaffType::Stylist);

    // The details modal: same-email save with a phone change must pass the
    // cross-agency guard (no false "shared account" block).
    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerEdit', $membership->id)
        ->set('ownerPhone', '(555) 010-3131')
        ->call('saveOwnerDetails')
        ->assertHasNoErrors();
    expect($membership->fresh()->user->phone)->toBe('(555) 010-3131');
});

it('surfaces action-level rejections on a VISIBLE field — a manager save can never fail silently', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $membership = managerOf($salon)->membershipFor($salon);

    // Simulate the action layer refusing the save the way the pre-feature
    // code did for a bookable manager ("managers are never bookable") — a
    // domain-keyed ValidationException no modal field is bound to. This is
    // the live failure mode: mid-deploy/stale code rejected every checked
    // save, and the modal showed NOTHING.
    $throwing = new class extends UpdateStaffMembership
    {
        public function __construct() {}

        public function handle(User $actor, Salon $salon, SalonMembership $membership, array $data): SalonMembership
        {
            throw ValidationException::withMessages([
                'salon_role' => __('That role does not match: stylists are bookable; managers are not.'),
            ]);
        }
    };
    app()->instance(UpdateStaffMembership::class, $throwing);

    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->set('editTakesBookings', true)
        ->call('saveEdit')
        ->assertHasErrors(['editRole']) // re-keyed onto a rendered field
        ->assertSee('That role does not match')
        ->assertSet('showEdit', true); // the modal stays open WITH the message

    app()->forgetInstance(UpdateStaffMembership::class);
    expect($membership->fresh()->staff_type)->toBeNull(); // nothing persisted
});

it('surfaces transfer-flow rejections on the modal too — never a silent no-op', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $ownerMembership = $owner->membershipFor($salon);
    $operator = User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Admin,
    ]);

    $throwing = new class extends SetSalonOwner
    {
        public function __construct() {}

        public function handle(User $actor, Salon $salon, array $data): ProvisionedUser
        {
            throw ValidationException::withMessages([
                'owner' => __('Ownership assignment would leave :count owners — refused.', ['count' => 0]),
            ]);
        }
    };
    app()->instance(SetSalonOwner::class, $throwing);

    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerTransfer', $ownerMembership->id, 'deactivate')
        ->set('transferChoice', 'me')
        ->call('confirmTransfer')
        ->assertHasErrors(['transferChoice'])
        ->assertSet('showTransfer', true);

    app()->forgetInstance(SetSalonOwner::class);
    expect($ownerMembership->fresh()->salon_role)->toBe(SalonRole::Owner);
});

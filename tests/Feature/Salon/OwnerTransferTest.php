<?php

use App\Actions\Salons\SetSalonOwner;
use App\Actions\Staff\DeleteStaffUser;
use App\Actions\Staff\ResetStaffPassword;
use App\Actions\Staff\SetMembershipActive;
use App\Actions\Staff\UpdateStaffMembership;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Support\Permissions\SalonStaffRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Agency-wide member editing + the owner-transfer safeguard.
|
| Privileged agency roles (owner/admin) may edit EVERY member of their own
| agency's salons — stylists, managers, and salon owners — but a salon may
| never be left ownerless: any owner-stripping action (demote, deactivate,
| delete) demands a designated replacement in the same flow, either an
| existing member promoted or the acting operator taking over explicitly.
| Salon roles gain nothing; tenancy holds; the demo stays read-only.
*/

function transferOwnerCount(Salon $salon): int
{
    return $salon->memberships()
        ->where('salon_role', SalonRole::Owner->value)
        ->where('active', true)
        ->count();
}

// ---------------------------------------------------------------------------
// Agency operators can edit everyone — across their agency's salons
// ---------------------------------------------------------------------------

it('lets agency owner and admin edit stylists, managers, and the salon owner across salons', function () {
    Mail::fake();
    $agency = Agency::factory()->create();
    $salonA = Salon::factory()->for($agency)->create();
    $salonB = Salon::factory()->for($agency)->create();

    foreach ([AgencyRole::Owner, AgencyRole::Admin] as $i => $agencyRole) {
        $operator = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => $agencyRole]);
        $salon = [$salonA, $salonB][$i]; // each operator works a different salon
        $owner = salonOwnerOf($salon);
        $stylist = stylistOf($salon);
        $manager = frontDeskOf($salon);

        // Full target authority — the owner included.
        expect((new SalonStaffRoles)->manageable($operator, $salon))
            ->toEqualCanonicalizing([SalonRole::Owner, SalonRole::Manager, SalonRole::Stylist]);

        // Stylist and manager membership edits work.
        app(UpdateStaffMembership::class)->handle($operator, $salon, $stylist->membershipFor($salon), [
            'salon_role' => 'salon_manager',
        ]);
        expect($stylist->membershipFor($salon)->fresh()->salon_role)->toBe(SalonRole::Manager);

        app(UpdateStaffMembership::class)->handle($operator, $salon, $manager->membershipFor($salon), [
            'salon_role' => 'stylist', 'staff_type' => 'stylist',
        ]);
        expect($manager->membershipFor($salon)->fresh()->salon_role)->toBe(SalonRole::Stylist);

        // The OWNER is a reachable target now: password reset succeeds…
        app(ResetStaffPassword::class)->handle($operator, $salon, $owner->membershipFor($salon));
        expect($owner->fresh()->must_change_password)->toBeTrue();

        // …and their account details are editable from the salon Users page.
        Livewire::actingAs($operator)
            ->test('pages::salon.users.index', ['salon' => $salon])
            ->call('startOwnerEdit', $owner->membershipFor($salon)->id)
            ->set('ownerName', 'Edited By Agency')
            ->call('saveOwnerDetails')
            ->assertHasNoErrors();
        expect($owner->fresh()->name)->toBe('Edited By Agency');

        // Details editing reaches every member, not just the owner.
        Livewire::actingAs($operator)
            ->test('pages::salon.users.index', ['salon' => $salon])
            ->call('startOwnerEdit', $stylist->membershipFor($salon)->id)
            ->set('ownerName', 'Stylist Renamed')
            ->call('saveOwnerDetails')
            ->assertHasNoErrors();
        expect($stylist->fresh()->name)->toBe('Stylist Renamed');
    }
});

// ---------------------------------------------------------------------------
// Salon roles gain nothing
// ---------------------------------------------------------------------------

it('still refuses salon managers and stylists any authority over the owner — or ownership itself', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $manager = salonAdminOf($salon);
    $stylist = stylistOf($salon);
    $ownerMembership = $owner->membershipFor($salon);

    foreach ([$manager, $stylist] as $actor) {
        expect((new SalonStaffRoles)->canManage($actor, $salon, SalonRole::Owner))->toBeFalse();

        expect(fn () => app(UpdateStaffMembership::class)->handle($actor, $salon, $ownerMembership, [
            'salon_role' => 'salon_manager',
        ]))->toThrow(AuthorizationException::class);

        expect(fn () => app(ResetStaffPassword::class)->handle($actor, $salon, $ownerMembership))
            ->toThrow(AuthorizationException::class);

        // Taking ownership is out of reach in BOTH transfer modes.
        expect(fn () => app(SetSalonOwner::class)->handle($actor, $salon, ['make_actor_owner' => true]))
            ->toThrow(AuthorizationException::class);
        expect(fn () => app(SetSalonOwner::class)->handle($actor, $salon, [
            'membership_id' => $actor->membershipFor($salon)->id,
        ]))->toThrow(AuthorizationException::class);
    }

    // The manager reaches the page but not the transfer modal.
    Livewire::actingAs($manager)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerTransfer', $ownerMembership->id, 'demote')
        ->assertForbidden();

    expect($ownerMembership->fresh()->salon_role)->toBe(SalonRole::Owner);
    expect(transferOwnerCount($salon))->toBe(1);
});

// ---------------------------------------------------------------------------
// Tenancy boundary
// ---------------------------------------------------------------------------

it('refuses a cross-agency operator any member editing or transfer (tenant isolation)', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    $foreign = User::factory()->create([
        'agency_id' => Agency::factory()->create()->id,
        'agency_role' => AgencyRole::Admin,
    ]);

    expect((new SalonStaffRoles)->manageable($foreign, $salon))->toBe([]);

    // Not even non-owner members are reachable — let alone the owner.
    expect(fn () => app(UpdateStaffMembership::class)->handle($foreign, $salon, $stylist->membershipFor($salon), [
        'salon_role' => 'salon_manager',
    ]))->toThrow(AuthorizationException::class);

    expect(fn () => app(SetSalonOwner::class)->handle($foreign, $salon, ['make_actor_owner' => true]))
        ->toThrow(AuthorizationException::class);

    // The page itself is out of reach at the route boundary.
    $this->actingAs($foreign)->get(route('salon.users', $salon))->assertForbidden();

    expect($owner->membershipFor($salon)->fresh()->salon_role)->toBe(SalonRole::Owner);
});

// ---------------------------------------------------------------------------
// The safeguard: owner-stripping is blocked without a replacement
// ---------------------------------------------------------------------------

it('blocks demoting, deactivating, and deleting the sole owner without a designated replacement', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    salonAdminOf($salon); // another member exists, but was not designated
    $operator = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Admin]);
    $membership = $owner->membershipFor($salon);

    expect(fn () => app(UpdateStaffMembership::class)->handle($operator, $salon, $membership, [
        'salon_role' => 'salon_manager',
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(SetMembershipActive::class)->handle($operator, $salon, $membership, false))
        ->toThrow(ValidationException::class);

    expect(fn () => app(DeleteStaffUser::class)->handle($operator, $salon, $membership))
        ->toThrow(ValidationException::class);

    $fresh = $membership->fresh();
    expect($fresh->salon_role)->toBe(SalonRole::Owner);
    expect($fresh->active)->toBeTrue();
    expect($owner->fresh()->trashed())->toBeFalse();
    expect(transferOwnerCount($salon))->toBe(1);
});

it('demotes the owner through the transfer modal by promoting an existing member', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $manager = frontDeskOf($salon);
    $operator = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Admin]);
    $ownerMembership = $owner->membershipFor($salon);

    // Editing the owner's role away from Owner opens the transfer modal —
    // nothing has happened yet.
    $component = Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $ownerMembership->id)
        ->assertSet('editingOwner', true)
        ->set('editRole', 'stylist')
        ->call('saveEdit')
        ->assertSet('showTransfer', true)
        ->assertSee(__('This salon needs an owner — choose one'))
        ->assertSee(__('Make me the owner'));
    expect($ownerMembership->fresh()->salon_role)->toBe(SalonRole::Owner);

    // A missing choice keeps everything blocked.
    $component->call('confirmTransfer')->assertHasErrors(['transferChoice']);
    expect($ownerMembership->fresh()->salon_role)->toBe(SalonRole::Owner);

    // Designating the manager completes transfer + demotion in one flow.
    $component->set('transferChoice', (string) $manager->membershipFor($salon)->id)
        ->call('confirmTransfer')
        ->assertHasNoErrors();

    expect($manager->membershipFor($salon)->fresh()->salon_role)->toBe(SalonRole::Owner);
    $exOwner = $ownerMembership->fresh();
    expect($exOwner->salon_role)->toBe(SalonRole::Stylist); // the chosen demote role
    expect($exOwner->staff_type)->toBe(StaffType::Stylist);
    expect(transferOwnerCount($salon))->toBe(1);
});

it('deletes the owner through the transfer modal — the salon keeps an owner throughout', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $manager = frontDeskOf($salon);
    $operator = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Owner]);
    $ownerMembership = $owner->membershipFor($salon);

    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerTransfer', $ownerMembership->id, 'delete')
        ->assertSet('showTransfer', true)
        ->set('transferChoice', (string) $manager->membershipFor($salon)->id)
        ->call('confirmTransfer')
        ->assertHasNoErrors();

    expect($manager->membershipFor($salon)->fresh()->salon_role)->toBe(SalonRole::Owner);
    expect($salon->memberships()->where('user_id', $owner->id)->exists())->toBeFalse();
    expect($owner->fresh()->trashed())->toBeTrue(); // only access → account removed
    expect(transferOwnerCount($salon))->toBe(1);
});

it('lets the acting agency admin take ownership themselves — explicitly, never silently', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $operator = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Admin]);
    $ownerMembership = $owner->membershipFor($salon);

    expect($operator->membershipFor($salon))->toBeNull(); // no membership yet

    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerTransfer', $ownerMembership->id, 'deactivate')
        ->assertSet('showTransfer', true)
        ->set('transferChoice', 'me')
        ->call('confirmTransfer')
        ->assertHasNoErrors();

    // The operator now owns the salon via a freshly created membership…
    $mine = $operator->membershipFor($salon);
    expect($mine)->not->toBeNull();
    expect($mine->salon_role)->toBe(SalonRole::Owner);
    expect($mine->active)->toBeTrue();

    // …and the outgoing owner was demoted, then deactivated as requested.
    $exOwner = $ownerMembership->fresh();
    expect($exOwner->salon_role)->not->toBe(SalonRole::Owner);
    expect($exOwner->active)->toBeFalse();
    expect(transferOwnerCount($salon))->toBe(1);
});

it('never leaves a salon ownerless — the invariant is asserted server-side even on a forged transfer', function () {
    $salon = Salon::factory()->create();
    salonOwnerOf($salon);
    $operator = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Owner]);

    // A transfer to a nonexistent membership dies inside the transaction —
    // the incumbent survives untouched.
    expect(fn () => app(SetSalonOwner::class)->handle($operator, $salon, ['membership_id' => 999999]))
        ->toThrow(ModelNotFoundException::class);

    expect(transferOwnerCount($salon))->toBe(1);
});

// ---------------------------------------------------------------------------
// Demo stays read-only
// ---------------------------------------------------------------------------

it('keeps the demo Users page view-only: no member edits, no ownership transfer', function () {
    $salon = demoShowcase();
    $viewer = salonOwnerOf($salon); // the guest preview browses as an owner
    $operator = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Owner]);

    $ownerMembership = $salon->memberships()
        ->where('salon_role', SalonRole::Owner->value)
        ->where('user_id', '!=', $viewer->id)
        ->firstOrFail();
    $before = transferOwnerCount($salon);

    // Even a privileged agency operator of the demo agency cannot transfer:
    // the write is a blocked no-op.
    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerTransfer', $ownerMembership->id, 'demote')
        ->set('transferChoice', 'me')
        ->call('confirmTransfer')
        ->assertHasNoErrors();

    expect($ownerMembership->fresh()->salon_role)->toBe(SalonRole::Owner);
    expect($operator->membershipFor($salon))->toBeNull();
    expect(transferOwnerCount($salon))->toBe($before);

    // Membership edits and detail edits stay blocked for the demo viewer too.
    $stylistMembership = $salon->memberships()->where('staff_type', StaffType::Stylist->value)->firstOrFail();
    Livewire::actingAs($viewer)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $stylistMembership->id)
        ->set('editRole', 'salon_manager')
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($stylistMembership->fresh()->staff_type)->toBe(StaffType::Stylist);
});

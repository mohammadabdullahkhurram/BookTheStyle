<?php

use App\Actions\Staff\UpdateMemberDetails;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| The shared-account guard: one login can hold salon memberships under
| DIFFERENT agencies. Email is the login identifier, so an agency operator
| editing a shared account's email would repoint where that person signs in
| for the OTHER agency too. UpdateMemberDetails refuses that server-side;
| name/phone stay editable with an account-wide notice; accounts wholly
| inside the operator's agency are untouched by any of it; and the person
| themself always re-points their own login via account settings.
*/

const SHARED_NOTICE = 'This person also works at a salon under another agency';
const SHARED_BLOCK = "This account also belongs to another agency's salon, so its login email can only be changed by the account holder.";

function sharedGuardOperator(Salon $salon): User
{
    return User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Admin,
    ]);
}

it('lets an operator change the email of an account that lives only in their agency — no warning', function () {
    $agency = Agency::factory()->create();
    $salonA = Salon::factory()->for($agency)->create();
    $salonB = Salon::factory()->for($agency)->create();
    $operator = sharedGuardOperator($salonA);

    // Memberships in TWO salons — but both under the operator's agency.
    $stylist = stylistOf($salonA);
    stylistOf($salonB, $stylist);
    $membership = $stylist->membershipFor($salonA);

    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salonA])
        ->call('startOwnerEdit', $membership->id)
        ->assertSet('ownerEditShared', false)
        ->assertDontSee(__(SHARED_NOTICE))
        ->set('ownerEmail', 'new-login@example.com')
        ->call('saveOwnerDetails')
        ->assertHasNoErrors();

    expect($stylist->fresh()->email)->toBe('new-login@example.com');
});

it('refuses an email change on an account shared with another agency — server-side, not just UI', function () {
    $salon = Salon::factory()->create();
    $foreignSalon = Salon::factory()->create(); // different agency
    $operator = sharedGuardOperator($salon);

    $shared = stylistOf($salon);
    stylistOf($foreignSalon, $shared); // the same login also works there
    $membership = $shared->membershipFor($salon);
    $originalEmail = $shared->email;

    // The page: warning shown, email change rejected under the field.
    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerEdit', $membership->id)
        ->assertSet('ownerEditShared', true)
        ->assertSee(__(SHARED_NOTICE))
        ->set('ownerEmail', 'attacker@example.com')
        ->call('saveOwnerDetails')
        ->assertHasErrors(['ownerEmail']);
    expect($shared->fresh()->email)->toBe($originalEmail);

    // The action layer itself refuses too — no UI required to bypass.
    expect(fn () => app(UpdateMemberDetails::class)->handle($operator, $salon, $membership, [
        'name' => $shared->name, 'email' => 'attacker@example.com', 'phone' => null,
    ]))->toThrow(ValidationException::class, SHARED_BLOCK);
    expect($shared->fresh()->email)->toBe($originalEmail);
});

it('still lets an operator edit a shared account\'s name and phone — email untouched, no false block', function () {
    $salon = Salon::factory()->create();
    $foreignSalon = Salon::factory()->create();
    $operator = sharedGuardOperator($salon);

    $shared = stylistOf($salon);
    stylistOf($foreignSalon, $shared);
    $membership = $shared->membershipFor($salon);
    $originalEmail = $shared->email;

    // Same email re-submitted + new name/phone: succeeds — the guard fires
    // on CHANGE, not presence.
    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerEdit', $membership->id)
        ->set('ownerName', 'Renamed Everywhere')
        ->set('ownerPhone', '(555) 010-9000')
        ->call('saveOwnerDetails')
        ->assertHasNoErrors();

    $fresh = $shared->fresh();
    expect($fresh->name)->toBe('Renamed Everywhere');
    expect($fresh->phone)->toBe('(555) 010-9000');
    expect($fresh->email)->toBe($originalEmail);
});

it('treats an owner shared through another agency\'s salon the same way', function () {
    $salon = Salon::factory()->create();
    $foreignSalon = Salon::factory()->create();
    $operator = sharedGuardOperator($salon);

    $owner = salonOwnerOf($salon);
    stylistOf($foreignSalon, $owner); // the owner also rents a chair elsewhere
    $membership = $owner->membershipFor($salon);
    $originalEmail = $owner->email;

    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerEdit', $membership->id)
        ->assertSet('ownerEditShared', true)
        ->set('ownerEmail', 'repointed@example.com')
        ->call('saveOwnerDetails')
        ->assertHasErrors(['ownerEmail']);

    expect($owner->fresh()->email)->toBe($originalEmail);
    expect($membership->fresh()->salon_role)->toBe(SalonRole::Owner);
});

it('lets the account holder change their own email through profile settings — shared or not', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create(); // different agency: a shared account
    $shared = stylistOf($salonA);
    stylistOf($salonB, $shared);

    Livewire::actingAs($shared)
        ->test('pages::settings.profile')
        ->set('email', 'my-new-login@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($shared->fresh()->email)->toBe('my-new-login@example.com');
});

it('keeps regular flows untouched: membership edits on a shared account, and the tenancy boundary', function () {
    $salon = Salon::factory()->create();
    $foreignSalon = Salon::factory()->create();
    $manager = salonAdminOf($salon);
    $operator = sharedGuardOperator($salon);

    $shared = stylistOf($salon);
    stylistOf($foreignSalon, $shared);
    $membership = $shared->membershipFor($salon);

    // A salon manager's ROLE edit of a shared member is not email-touching
    // and keeps working exactly as before.
    Livewire::actingAs($manager)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->set('editRole', 'salon_manager')
        ->call('saveEdit')
        ->assertHasNoErrors();
    expect($membership->fresh()->salon_role)->toBe(SalonRole::Manager);

    // Salon roles still can't reach the details surface at all.
    Livewire::actingAs($manager)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerEdit', $membership->id)
        ->assertForbidden();

    // Tenant isolation: a cross-agency operator is refused at the action.
    $foreignOperator = sharedGuardOperator($foreignSalon);
    expect(fn () => app(UpdateMemberDetails::class)->handle($foreignOperator, $salon, $membership->fresh(), [
        'name' => 'X', 'email' => $shared->email, 'phone' => null,
    ]))->toThrow(AuthorizationException::class);

    // And the operator's OWN action call for a same-agency, non-shared
    // account is exactly as permissive as before.
    $local = stylistOf($salon);
    app(UpdateMemberDetails::class)->handle($operator, $salon, $local->membershipFor($salon), [
        'name' => $local->name, 'email' => 'local-rename@example.com', 'phone' => null,
    ]);
    expect($local->fresh()->email)->toBe('local-rename@example.com');
});

it('keeps the demo Users page read-only for member details', function () {
    $salon = demoShowcase();
    $operator = User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Owner,
    ]);

    $membership = $salon->memberships()->with('user')->firstOrFail();
    $before = $membership->user->email;

    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerEdit', $membership->id)
        ->set('ownerEmail', 'demo-tamper@example.com')
        ->call('saveOwnerDetails')
        ->assertHasNoErrors(); // a blocked no-op, not an error

    expect($membership->user->fresh()->email)->toBe($before);
});

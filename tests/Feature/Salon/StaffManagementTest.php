<?php

use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\UpdateStaffMembership;
use App\Enums\SalonRole;
use App\Mail\StaffInviteMail;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Support\Permissions\SalonStaffRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;

// salonOwnerOf / salonAdminOf / stylistOf / frontDeskOf live in tests/Pest.php.

/*
| Tenant isolation + anti-escalation for salon staff management.
*/

it('forbids a salon admin from reaching staff in another salon (no IDOR)', function () {
    $agency = Agency::factory()->create();
    $salonA = Salon::factory()->for($agency)->create();
    $salonB = Salon::factory()->for($agency)->create();

    $adminA = salonAdminOf($salonA);

    $this->actingAs($adminA)->get(route('salon.staff', $salonA))->assertOk();
    $this->actingAs($adminA)->get(route('salon.staff', $salonB))->assertForbidden();
});

it('forbids the invite action across salons even if the route is bypassed', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $adminA = salonAdminOf($salonA);

    expect(fn () => app(InviteStaff::class)->handle($adminA, $salonB, [
        'name' => 'X', 'email' => 'x@example.com', 'salon_role' => 'salon_manager',
    ]))->toThrow(AuthorizationException::class);
});

it('lets a salon admin manage staff and admins — but NEVER grant or touch the owner', function () {
    $salon = Salon::factory()->create();
    $admin = salonAdminOf($salon);

    // Full admin surface: an admin may grant Staff and Admin — never Owner.
    $assignable = (new SalonStaffRoles)->assignable($admin, $salon);
    expect($assignable)->toEqualCanonicalizing([SalonRole::Manager, SalonRole::Stylist]);
    expect($assignable)->not->toContain(SalonRole::Owner);

    // Inviting an owner is rejected server-side; inviting an admin works.
    expect(fn () => app(InviteStaff::class)->handle($admin, $salon, [
        'name' => 'X', 'email' => 'x@example.com', 'salon_role' => 'salon_owner',
    ]))->toThrow(AuthorizationException::class);

    $peer = app(InviteStaff::class)->handle($admin, $salon, [
        'name' => 'Y', 'email' => 'y@example.com', 'salon_role' => 'salon_manager',
    ]);
    expect($peer->user->membershipFor($salon)->salon_role)->toBe(SalonRole::Manager);
});

it('forbids a salon admin from editing an owner membership', function () {
    $salon = Salon::factory()->create();
    $admin = salonAdminOf($salon);
    $owner = salonOwnerOf($salon);
    $ownerMembership = $owner->membershipFor($salon);

    expect(fn () => app(UpdateStaffMembership::class)->handle($admin, $salon, $ownerMembership, [
        'salon_role' => 'salon_manager',
    ]))->toThrow(AuthorizationException::class);
});

it('lets a salon owner grant admin and staff — never a second owner', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    expect((new SalonStaffRoles)->assignable($owner, $salon))
        ->toEqualCanonicalizing([SalonRole::Manager, SalonRole::Stylist]);
});

it('issues a temp password + forces change for new staff, and emails it', function () {
    Mail::fake();

    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $result = app(InviteStaff::class)->handle($owner, $salon, [
        'name' => 'New Stylist',
        'email' => 'new.stylist@example.com',
        'salon_role' => 'stylist',
        'staff_type' => 'stylist',
    ]);

    expect($result->temporaryPassword)->not->toBeNull();
    expect($result->user->must_change_password)->toBeTrue();
    expect($result->user->salonMemberships()->where('salon_id', $salon->id)->exists())->toBeTrue();

    Mail::assertQueued(StaffInviteMail::class, fn ($mail) => $mail->hasTo('new.stylist@example.com')
        && $mail->temporaryPassword === $result->temporaryPassword);

    // Forced to change on first login.
    $this->actingAs($result->user->fresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('password.change'));
});

it('adds an existing user to a salon without issuing new credentials', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $existing = User::factory()->create(['email' => 'already@example.com', 'must_change_password' => false]);

    $result = app(InviteStaff::class)->handle($owner, $salon, [
        'name' => 'Ignored', 'email' => 'already@example.com', 'salon_role' => 'salon_manager',
    ]);

    expect($result->existing)->toBeTrue();
    expect($result->temporaryPassword)->toBeNull();
    expect($existing->fresh()->must_change_password)->toBeFalse();
    expect($existing->membershipFor($salon))->not->toBeNull();
});

<?php

use App\Actions\AgencyUsers\UpdateAgencyUser;
use App\Actions\Salons\CreateSalon;
use App\Actions\Staff\ResetStaffPassword;
use App\Actions\Staff\SetMembershipActive;
use App\Actions\Staff\UpdateStaffMembership;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Mail\AccountCreatedMail;
use App\Mail\StaffInviteMail;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| The role/permission model (SPEC §2): agency owner is a protected singleton;
| the salon owner is auto-provisioned from the contact person and untouchable;
| salon admins (managers + front desk) hold the full admin surface except
| anything owner-touching; staff (stylists) are unchanged; the role remap
| migration maps production rows correctly. Server-side enforcement throughout.
*/

function roleAgency(): array
{
    $agency = Agency::factory()->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $admin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);

    return [$agency, $owner, $admin];
}

// ---------------------------------------------------------------------------
// The remap migration maps existing rows correctly
// ---------------------------------------------------------------------------

it('remaps legacy member roles by staff type: stylists → staff, managers/front desk → admin', function () {
    $salon = Salon::factory()->create();
    $rows = [];
    foreach ([['stylist'], ['front_desk'], ['manager'], [null]] as [$type]) {
        $user = User::factory()->create();
        $id = DB::table('salon_memberships')->insertGetId([
            'salon_id' => $salon->id, 'user_id' => $user->id,
            'salon_role' => 'user', 'staff_type' => $type, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $rows[$type ?? 'none'] = $id;
    }
    $ownerRow = SalonMembership::factory()->for($salon)->owner()->create();

    // Replay the migration's up() against these legacy rows.
    $migration = include base_path('database/migrations/2026_07_26_000001_remap_salon_member_roles.php');
    $migration->up();

    expect(DB::table('salon_memberships')->find($rows['stylist'])->salon_role)->toBe('staff');
    expect(DB::table('salon_memberships')->find($rows['front_desk'])->salon_role)->toBe('salon_admin');
    expect(DB::table('salon_memberships')->find($rows['manager'])->salon_role)->toBe('salon_admin');
    expect(DB::table('salon_memberships')->find($rows['none'])->salon_role)->toBe('staff');
    // Owners/admins untouched.
    expect($ownerRow->fresh()->salon_role)->toBe(SalonRole::Owner);
});

// ---------------------------------------------------------------------------
// Agency owner: exactly one, ever — and untouchable
// ---------------------------------------------------------------------------

it('lets no path create or promote to a second agency owner', function () {
    [$agency, $owner, $admin] = roleAgency();
    $user = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);

    // Promotion to owner is rejected for every actor, the owner included.
    foreach ([$owner, $admin] as $actor) {
        expect(fn () => app(UpdateAgencyUser::class)->handle($actor, $agency, $user, [
            'name' => $user->name, 'agency_role' => 'agency_owner',
        ]))->toThrow(AuthorizationException::class);
    }
});

it('makes the agency owner untouchable — even agency admins get a server-side rejection', function () {
    [$agency, $owner, $admin] = roleAgency();

    expect(fn () => app(UpdateAgencyUser::class)->handle($admin, $agency, $owner, [
        'name' => 'Renamed Owner', 'agency_role' => 'agency_admin',
    ]))->toThrow(AuthorizationException::class);

    expect($owner->fresh()->agency_role)->toBe(AgencyRole::Owner);
});

it('lets the agency owner still edit their own account, but never self-delete', function () {
    [, $owner] = roleAgency();

    $this->actingAs($owner);
    Livewire::test('pages::settings.profile')
        ->set('name', 'Renamed Myself')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();
    expect($owner->fresh()->name)->toBe('Renamed Myself');

    // The singleton operator account cannot orphan the agency.
    expect($owner->fresh()->canDeleteOwnAccount())->toBeFalse();
    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertForbidden();
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Salon owner: auto-provisioned from the contact person, then protected
// ---------------------------------------------------------------------------

it('provisions the contact person as salon OWNER at creation, with the standard invite mails', function () {
    Mail::fake();
    [$agency, $agencyOwner] = roleAgency();

    $salon = app(CreateSalon::class)->handle($agencyOwner, $agency, salonProfileInput([
        'slug' => 'owner-provision', 'timezone' => 'America/New_York',
    ]));

    $contact = User::query()->where('email', $salon->contact_email)->firstOrFail();
    $membership = $contact->membershipFor($salon);

    expect($membership->salon_role)->toBe(SalonRole::Owner);
    expect($contact->must_change_password)->toBeTrue();

    // The EXISTING provisioning path: welcome + credentialed invite.
    Mail::assertQueued(AccountCreatedMail::class, fn ($mail) => $mail->hasTo($salon->contact_email));
    Mail::assertQueued(StaffInviteMail::class, fn ($mail) => $mail->hasTo($salon->contact_email)
        && $mail->temporaryPassword !== null);
});

it('links an EXISTING account — including the agency owner\'s own — as salon owner, no new credentials', function () {
    Mail::fake();
    [$agency, $agencyOwner] = roleAgency();

    $salon = app(CreateSalon::class)->handle($agencyOwner, $agency, salonProfileInput([
        'slug' => 'owner-linked', 'timezone' => 'America/New_York',
        'contact_name' => $agencyOwner->name, 'contact_email' => $agencyOwner->email,
    ]));

    expect($agencyOwner->membershipFor($salon)->salon_role)->toBe(SalonRole::Owner);
    expect($agencyOwner->fresh()->must_change_password)->toBeFalse();
    Mail::assertQueued(StaffInviteMail::class, fn ($mail) => $mail->hasTo($agencyOwner->email)
        && $mail->temporaryPassword === null);
});

it('shields the salon owner from every staff-management action — admins AND agency operators', function () {
    [$agency, $agencyOwner] = roleAgency();
    $salon = Salon::factory()->for($agency)->create();
    $ownerMembership = salonOwnerOf($salon)->membershipFor($salon);
    $salonAdmin = salonAdminOf($salon);

    foreach ([$salonAdmin, $agencyOwner] as $actor) {
        expect(fn () => app(UpdateStaffMembership::class)->handle($actor, $salon, $ownerMembership, [
            'salon_role' => 'salon_admin',
        ]))->toThrow(AuthorizationException::class);
        expect(fn () => app(SetMembershipActive::class)->handle($actor, $salon, $ownerMembership, false))
            ->toThrow(AuthorizationException::class);
        expect(fn () => app(ResetStaffPassword::class)->handle($actor, $salon, $ownerMembership))
            ->toThrow(AuthorizationException::class);
    }

    expect($ownerMembership->fresh()->salon_role)->toBe(SalonRole::Owner);
    expect($ownerMembership->fresh()->active)->toBeTrue();
});

it('repairs an ownerless salon via the backfill command, dry-run first', function () {
    Mail::fake();
    [$agency] = roleAgency();
    $salon = Salon::factory()->for($agency)->create(); // no owner membership

    $this->artisan('salons:provision-owners')
        ->expectsOutputToContain($salon->slug)
        ->assertExitCode(0);
    expect($salon->memberships()->where('salon_role', SalonRole::Owner->value)->exists())->toBeFalse();

    $this->artisan('salons:provision-owners', ['--force' => true])->assertExitCode(0);
    $owner = User::query()->where('email', $salon->contact_email)->firstOrFail();
    expect($owner->membershipFor($salon)->salon_role)->toBe(SalonRole::Owner);
});

// ---------------------------------------------------------------------------
// Deletion rules
// ---------------------------------------------------------------------------

it('lets only the salon owner self-delete; admins and staff are salon-managed', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $admin = salonAdminOf($salon);
    $stylist = stylistOf($salon);

    expect($owner->canDeleteOwnAccount())->toBeTrue();
    expect($admin->canDeleteOwnAccount())->toBeFalse();
    expect($stylist->canDeleteOwnAccount())->toBeFalse();

    // Server-side 403 for a salon admin, hidden UI notwithstanding.
    $this->actingAs($admin);
    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertForbidden();
    expect(User::query()->whereKey($admin->id)->exists())->toBeTrue();

    // The salon owner may.
    $this->actingAs($owner);
    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});

it('reserves salon deletion for the owner and the agency override — never salon admins', function () {
    [$agency, $agencyOwner] = roleAgency();
    $salon = Salon::factory()->for($agency)->create();
    $owner = salonOwnerOf($salon);
    $admin = salonAdminOf($salon);

    expect($owner->can('delete', $salon))->toBeTrue();
    expect($admin->can('delete', $salon))->toBeFalse();
    expect(stylistOf($salon)->can('delete', $salon))->toBeFalse();
    // The agency retains the platform override (deliberate — see SPEC §2).
    expect($agencyOwner->can('delete', $salon))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Cross-salon isolation intact after the widening
// ---------------------------------------------------------------------------

it('confines the widened admin surface to the admin\'s own salon', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $frontDeskA = frontDeskOf($salonA);

    foreach (['manage', 'manageBookings', 'manageGhlConnection', 'viewMasterCalendar'] as $ability) {
        expect($frontDeskA->can($ability, $salonA))->toBeTrue();
        expect($frontDeskA->can($ability, $salonB))->toBeFalse();
    }

    $this->actingAs($frontDeskA)->get(route('salon.settings', $salonB))->assertForbidden();
});

// ---------------------------------------------------------------------------
// Mailables render the logo, not the wordmark
// ---------------------------------------------------------------------------

it('renders the logo in every mailable: absolute PNG URL, brand alt text', function () {
    $html = (new AccountCreatedMail('Nina New', 'Glow Bar', 'https://example.test/login'))->render();

    expect($html)
        ->toContain('images/full-logo.png')
        ->toContain('alt="BookTheStyle"');

    // Absolute URL (relative paths do not resolve in mail clients).
    preg_match('/<img[^>]*full-logo[^>]*>/', $html, $m);
    expect($m[0] ?? '')->toContain('src="http');
    expect($m[0] ?? '')->toContain('height="38"');
});

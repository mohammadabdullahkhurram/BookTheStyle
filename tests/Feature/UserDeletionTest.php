<?php

use App\Actions\AgencyUsers\CreateAgencyUser;
use App\Actions\AgencyUsers\DeleteAgencyUser;
use App\Actions\Staff\DeleteStaffUser;
use App\Actions\Staff\InviteStaff;
use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\BookingItem;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
| User deletion respects the role matrix (SPEC §2) server-side, is soft
| (booking_items.stylist_id CASCADES on hard delete — a hard delete would
| destroy booking history), and never crosses tenant lines: the salon
| surface removes THIS salon's membership; the account goes only when
| nothing else references it.
*/

function deleteStaff(User $actor, Salon $salon, SalonMembership $membership): bool
{
    return app(DeleteStaffUser::class)->handle($actor, $salon, $membership);
}

// ---------------------------------------------------------------------------
// Salon surface: who can delete whom
// ---------------------------------------------------------------------------

it('lets the salon owner delete an admin and a staff member', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $admin = salonAdminOf($salon);
    $stylist = stylistOf($salon);

    foreach ([$admin, $stylist] as $target) {
        $membership = $salon->memberships()->where('user_id', $target->id)->firstOrFail();
        expect(deleteStaff($owner, $salon, $membership))->toBeTrue();
        expect($target->fresh()->trashed())->toBeTrue();
        expect($salon->memberships()->where('user_id', $target->id)->exists())->toBeFalse();
    }
});

it('lets a salon admin delete staff but never the owner', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $admin = salonAdminOf($salon);
    $stylist = stylistOf($salon);

    $stylistMembership = $salon->memberships()->where('user_id', $stylist->id)->firstOrFail();
    expect(deleteStaff($admin, $salon, $stylistMembership))->toBeTrue();

    $ownerMembership = $salon->memberships()->where('user_id', $owner->id)->firstOrFail();
    expect(fn () => deleteStaff($admin, $salon, $ownerMembership))
        ->toThrow(AuthorizationException::class);
    expect($owner->fresh()->trashed())->toBeFalse();
});

it('refuses the owner as a deletion target for everyone, agency operators included', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $agencyOwner = User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Owner,
    ]);

    $ownerMembership = $salon->memberships()->where('user_id', $owner->id)->firstOrFail();
    expect(fn () => deleteStaff($agencyOwner, $salon, $ownerMembership))
        ->toThrow(AuthorizationException::class);
});

it('gives staff no deletion rights at all', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $other = stylistOf($salon);

    $membership = $salon->memberships()->where('user_id', $other->id)->firstOrFail();
    expect(fn () => deleteStaff($stylist, $salon, $membership))
        ->toThrow(AuthorizationException::class);
});

it('refuses self-deletion from the staff surface', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $membership = $salon->memberships()->where('user_id', $owner->id)->firstOrFail();
    expect(fn () => deleteStaff($owner, $salon, $membership))
        ->toThrow(AuthorizationException::class);
});

it('refuses an actor from another salon (tenant isolation)', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $ownerB = salonOwnerOf($salonB);
    $stylistA = stylistOf($salonA);

    $membership = $salonA->memberships()->where('user_id', $stylistA->id)->firstOrFail();
    expect(fn () => deleteStaff($ownerB, $salonA, $membership))
        ->toThrow(AuthorizationException::class);
    // …and a cross-salon membership handle 403s too.
    expect(fn () => deleteStaff($ownerB, $salonB, $membership))
        ->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------------------
// Multi-salon: membership goes, the account survives elsewhere
// ---------------------------------------------------------------------------

it('keeps the account and other-salon access when deleting a multi-salon member', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $person = stylistOf($salonA);
    SalonMembership::factory()->for($person)->for($salonB)->stylist()->create();

    $membershipA = $salonA->memberships()->where('user_id', $person->id)->firstOrFail();
    expect(deleteStaff(salonOwnerOf($salonA), $salonA, $membershipA))->toBeFalse();

    expect($person->fresh()->trashed())->toBeFalse();
    expect($salonB->memberships()->where('user_id', $person->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Booking history survives; deleted stylists leave the booking surface
// ---------------------------------------------------------------------------

it('keeps booking history — with the stylist name — after the stylist is deleted', function () {
    // The booking helpers' frozen clock (see tests/Pest.php).
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist);
    $booking = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00');
    Carbon::setTestNow();
    $itemId = $booking->items()->firstOrFail()->id;

    $membership = $salon->memberships()->where('user_id', $stylist->id)->firstOrFail();
    deleteStaff($owner, $salon, $membership);

    $item = BookingItem::query()->findOrFail($itemId);
    expect($item->stylist)->not->toBeNull();          // withTrashed history relation
    expect($item->stylist->name)->toBe($stylist->name);
    expect($booking->fresh())->not->toBeNull();

    // …but the deleted stylist no longer appears bookable for the service.
    expect($service->stylists()->pluck('users.id')->all())->not->toContain($stylist->id);
});

it('removes passkeys on deletion so passkey login dies with the account', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    DB::table('passkeys')->insert([
        'user_id' => $stylist->id,
        'name' => 'phone',
        'credential_id' => 'cred-123',
        'credential' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $membership = $salon->memberships()->where('user_id', $stylist->id)->firstOrFail();
    deleteStaff(salonOwnerOf($salon), $salon, $membership);

    expect(DB::table('passkeys')->where('user_id', $stylist->id)->exists())->toBeFalse();
});

it('turns a deleted user away at login with the generic message', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $stylist->forceFill(['password' => 'their-password-9'])->save();

    $membership = $salon->memberships()->where('user_id', $stylist->id)->firstOrFail();
    deleteStaff(salonOwnerOf($salon), $salon, $membership);

    $this->post(route('login.store'), ['email' => $stylist->email, 'password' => 'their-password-9'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();
});

// ---------------------------------------------------------------------------
// Agency surface
// ---------------------------------------------------------------------------

it('lets the agency owner delete admins and users, and an admin delete users only', function () {
    $agency = Agency::factory()->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $admin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);
    $admin2 = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);
    $user = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);
    $user2 = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);

    app(DeleteAgencyUser::class)->handle($owner, $admin2);
    expect($admin2->fresh()->trashed())->toBeTrue();

    app(DeleteAgencyUser::class)->handle($admin, $user2);
    expect($user2->fresh()->trashed())->toBeTrue();

    expect(fn () => app(DeleteAgencyUser::class)->handle($admin, User::factory()->create([
        'agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin,
    ])))->toThrow(AuthorizationException::class);

    expect($user->fresh()->trashed())->toBeFalse();
});

it('lets nobody delete the agency owner', function () {
    $agency = Agency::factory()->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $admin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);

    expect(fn () => app(DeleteAgencyUser::class)->handle($admin, $owner))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(DeleteAgencyUser::class)->handle($owner, $owner))
        ->toThrow(AuthorizationException::class);
    expect($owner->fresh()->trashed())->toBeFalse();
});

it('refuses cross-agency deletion', function () {
    $actor = User::factory()->create(['agency_id' => Agency::factory()->create()->id, 'agency_role' => AgencyRole::Owner]);
    $target = User::factory()->create(['agency_id' => Agency::factory()->create()->id, 'agency_role' => AgencyRole::User]);

    expect(fn () => app(DeleteAgencyUser::class)->handle($actor, $target))
        ->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------------------
// Re-provisioning a deleted person restores the same account
// ---------------------------------------------------------------------------

it('restores a deleted account on re-invite, keeping the same user id', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $originalId = $stylist->id;
    $email = $stylist->email;

    $membership = $salon->memberships()->where('user_id', $stylist->id)->firstOrFail();
    deleteStaff(salonOwnerOf($salon), $salon, $membership);
    expect($stylist->fresh()->trashed())->toBeTrue();

    $result = app(InviteStaff::class)->handle(salonOwnerOf($salon), $salon, [
        'name' => 'Back Again',
        'email' => $email,
        'salon_role' => 'staff',
        'staff_type' => 'stylist',
    ]);

    expect($result->user->id)->toBe($originalId);       // history keeps its owner
    expect($result->user->trashed())->toBeFalse();
    expect($result->temporaryPassword)->not->toBeNull();
    expect($result->user->must_change_password)->toBeTrue();
    expect($salon->memberships()->where('user_id', $originalId)->where('active', true)->exists())->toBeTrue();
});

it('restores a deleted account when re-created on the agency side', function () {
    Mail::fake();
    $agency = Agency::factory()->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $target = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);
    $originalId = $target->id;

    app(DeleteAgencyUser::class)->handle($owner, $target);

    $result = app(CreateAgencyUser::class)->handle($owner, $agency, [
        'name' => 'Back Again',
        'email' => $target->email,
        'agency_role' => 'agency_user',
    ]);

    expect($result->user->id)->toBe($originalId);
    expect($result->user->trashed())->toBeFalse();
});

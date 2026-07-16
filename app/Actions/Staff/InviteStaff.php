<?php

namespace App\Actions\Staff;

use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Mail\AccountCreatedMail;
use App\Mail\StaffInviteMail;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use App\Support\Permissions\SalonStaffRoles;
use App\Support\ProvisionedUser;
use App\Support\TemporaryPassword;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Invite/create a staff member for a salon. Enforces the anti-escalation rule
 * (the actor may not grant a role they aren't allowed to) server-side, never
 * trusting the UI — and CATEGORICALLY refuses Owner: owners are assigned from
 * the agency console (SetSalonOwner) or provisioned at salon creation, never
 * invited, no matter who asks. New users get a cryptographically-random
 * temporary password + must_change_password; the plaintext is returned for
 * one-time display and emailed in the branded staff-invite mail (alongside a
 * welcome email). An existing user (matched by email) is simply added as an
 * extra membership — no new credentials.
 */
class InviteStaff
{
    public function __construct(private SalonStaffRoles $roles) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null, salon_role: string, staff_type?: string|null}  $data
     */
    public function handle(User $actor, Salon $salon, array $data): ProvisionedUser
    {
        $role = SalonRole::from($data['salon_role']);

        // Hidden-but-reachable is a hole: even an actor whose UI never offers
        // Owner gets refused here, agency owners included.
        if ($role === SalonRole::Owner) {
            throw new AuthorizationException('Owners are assigned from the agency console, never invited.');
        }

        if (! $this->roles->canAssign($actor, $salon, $role)) {
            throw new AuthorizationException('You may not grant that role.');
        }

        // staff_type is the bookability flag and follows the role (stylists
        // bookable, managers not). Enforced so the pairing never drifts.
        $staffType = ! empty($data['staff_type'])
            ? StaffType::from($data['staff_type'])
            : $this->roles->impliedType($role);

        $this->roles->assertRoleMatchesType($role, $staffType);

        // Overwriting an existing membership needs authority over its CURRENT
        // role too — the invite form can't demote an owner/manager the actor
        // may not manage.
        $existing = User::withTrashed()->where('email', $data['email'])->first();
        if ($existing !== null && ! $existing->trashed()) {
            $current = $salon->memberships()->where('user_id', $existing->id)->first();

            if ($current !== null && ! $this->roles->canAssign($actor, $salon, $current->salon_role)) {
                throw new AuthorizationException('You may not manage that staff member.');
            }
        }

        return $this->provision($salon, $data, $role, $staffType);
    }

    /**
     * The ONE owner-provisioning engine — same temp password, same branded
     * mails, same restore-on-reinvite semantics as a staff invite. Carries NO
     * actor gate on purpose: it is reachable only through salon creation
     * (CreateSalon), the ownerless backfill command, and the agency console's
     * SetSalonOwner — each of which enforces its own authorization first.
     *
     * @param  array{name: string, email: string, phone?: string|null}  $contact
     */
    public function provisionOwner(Salon $salon, array $contact): ProvisionedUser
    {
        return $this->provision($salon, [
            ...$contact,
            'salon_role' => SalonRole::Owner->value,
        ], SalonRole::Owner, null);
    }

    /**
     * Shared provisioning core: restore a deleted account, link an existing
     * one, or create a new one with credentials — then attach the membership.
     *
     * @param  array{name: string, email: string, phone?: string|null}  $data
     */
    private function provision(Salon $salon, array $data, SalonRole $role, ?StaffType $staffType): ProvisionedUser
    {
        $phone = isset($data['phone']) && $data['phone'] !== '' ? $data['phone'] : null;

        $existing = User::withTrashed()->where('email', $data['email'])->first();

        // Re-inviting a DELETED person restores their account (history keeps
        // its owner; the unique email would block a fresh row anyway) and
        // issues new credentials exactly like a brand-new invite.
        if ($existing !== null && $existing->trashed()) {
            $temporaryPassword = TemporaryPassword::generate();

            DB::transaction(function () use ($existing, $data, $salon, $role, $staffType, $temporaryPassword, $phone): void {
                $existing->restore();
                $existing->forceFill([
                    'name' => $data['name'],
                    'phone' => $phone,
                    'password' => $temporaryPassword,
                    'must_change_password' => true,
                ])->save();

                SalonMembership::updateOrCreate(
                    ['user_id' => $existing->id, 'salon_id' => $salon->id],
                    ['salon_role' => $role, 'staff_type' => $staffType, 'active' => true],
                );
            });

            rescue(fn () => Mail::to($existing->email)->send(
                new StaffInviteMail($existing->name, $salon->name, $role->label(), $temporaryPassword, route('login')),
            ));

            return new ProvisionedUser($existing, $temporaryPassword);
        }

        if ($existing !== null) {
            // Promoting an existing member to Owner keeps their bookability
            // flag (the owner-who-cuts-hair case); other roles set their own.
            $current = $salon->memberships()->where('user_id', $existing->id)->first();
            $keepType = $role === SalonRole::Owner && $current !== null
                ? $current->staff_type
                : $staffType;

            SalonMembership::updateOrCreate(
                ['user_id' => $existing->id, 'salon_id' => $salon->id],
                ['salon_role' => $role, 'staff_type' => $keepType, 'active' => true],
            );

            // Existing login, new salon/role: an invite without credentials.
            rescue(fn () => Mail::to($existing->email)->send(
                new StaffInviteMail($existing->name, $salon->name, $role->label(), null, route('login')),
            ));

            return new ProvisionedUser($existing, temporaryPassword: null, existing: true);
        }

        $temporaryPassword = TemporaryPassword::generate();

        $user = DB::transaction(function () use ($data, $salon, $role, $staffType, $temporaryPassword, $phone) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $phone,
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ]);

            $salon->memberships()->create([
                'user_id' => $user->id,
                'salon_role' => $role,
                'staff_type' => $staffType,
                'active' => true,
            ]);

            return $user;
        });

        // Welcome + invite (with the temp password) — queued, and fail-safe:
        // rescue() means a broken mail transport only gets reported, while the
        // plaintext still returns for one-time in-app display, so nobody is
        // ever locked out. The password is never logged.
        rescue(fn () => Mail::to($user->email)->send(
            new AccountCreatedMail($user->name, $salon->name, route('login')),
        ));
        rescue(fn () => Mail::to($user->email)->send(
            new StaffInviteMail($user->name, $salon->name, $role->label(), $temporaryPassword, route('login')),
        ));

        return new ProvisionedUser($user, $temporaryPassword);
    }
}

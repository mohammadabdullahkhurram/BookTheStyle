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
 * trusting the UI. New users get a cryptographically-random temporary password
 * + must_change_password; the plaintext is returned for one-time display and
 * emailed in the branded staff-invite mail (alongside a welcome email). An existing user (matched by email) is simply added as an extra
 * membership — no new credentials.
 */
class InviteStaff
{
    public function __construct(private SalonStaffRoles $roles) {}

    /**
     * @param  array{name: string, email: string, salon_role: string, staff_type?: string|null}  $data
     */
    public function handle(User $actor, Salon $salon, array $data): ProvisionedUser
    {
        $role = SalonRole::from($data['salon_role']);

        if (! $this->roles->canAssign($actor, $salon, $role)) {
            throw new AuthorizationException('You may not grant that role.');
        }

        // Staff type records the member's operational function (stylist /
        // front desk / manager); the ROLE carries permissions, and the type
        // maps to it — enforced here so the pairing can never drift.
        $staffType = ! empty($data['staff_type'])
            ? StaffType::from($data['staff_type'])
            : null;

        $this->roles->assertRoleMatchesType($role, $staffType);

        $existing = User::withTrashed()->where('email', $data['email'])->first();

        // Re-inviting a DELETED person restores their account (history keeps
        // its owner; the unique email would block a fresh row anyway) and
        // issues new credentials exactly like a brand-new invite.
        if ($existing !== null && $existing->trashed()) {
            $temporaryPassword = TemporaryPassword::generate();

            DB::transaction(function () use ($existing, $data, $salon, $role, $staffType, $temporaryPassword): void {
                $existing->restore();
                $existing->forceFill([
                    'name' => $data['name'],
                    'password' => $temporaryPassword,
                    'must_change_password' => true,
                ])->save();

                $salon->memberships()->create([
                    'user_id' => $existing->id,
                    'salon_role' => $role,
                    'staff_type' => $staffType,
                    'active' => true,
                ]);
            });

            rescue(fn () => Mail::to($existing->email)->send(
                new StaffInviteMail($existing->name, $salon->name, $role->label(), $temporaryPassword, route('login')),
            ));

            return new ProvisionedUser($existing, $temporaryPassword);
        }

        if ($existing !== null) {
            $current = $salon->memberships()->where('user_id', $existing->id)->first();

            // If they already belong to this salon, the actor must also have
            // authority over their *current* role — so the invite form can't be
            // used to overwrite (e.g. demote) an owner/admin the actor may not
            // manage.
            if ($current !== null && ! $this->roles->canAssign($actor, $salon, $current->salon_role)) {
                throw new AuthorizationException('You may not manage that staff member.');
            }

            SalonMembership::updateOrCreate(
                ['user_id' => $existing->id, 'salon_id' => $salon->id],
                ['salon_role' => $role, 'staff_type' => $staffType, 'active' => true],
            );

            // Existing login, new salon: an invite without credentials.
            rescue(fn () => Mail::to($existing->email)->send(
                new StaffInviteMail($existing->name, $salon->name, $role->label(), null, route('login')),
            ));

            return new ProvisionedUser($existing, temporaryPassword: null, existing: true);
        }

        $temporaryPassword = TemporaryPassword::generate();

        $user = DB::transaction(function () use ($data, $salon, $role, $staffType, $temporaryPassword) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
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

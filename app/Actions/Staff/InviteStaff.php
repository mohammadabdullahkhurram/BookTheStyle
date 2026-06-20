<?php

namespace App\Actions\Staff;

use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use App\Support\Notifications\TemporaryPasswordChannel;
use App\Support\Permissions\SalonStaffRoles;
use App\Support\ProvisionedUser;
use App\Support\TemporaryPassword;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Invite/create a staff member for a salon. Enforces the anti-escalation rule
 * (the actor may not grant a role they aren't allowed to) server-side, never
 * trusting the UI. New users get a cryptographically-random temporary password
 * + must_change_password; the plaintext is returned for one-time display and
 * also emailed. An existing user (matched by email) is simply added as an extra
 * membership — no new credentials.
 */
class InviteStaff
{
    public function __construct(
        private SalonStaffRoles $roles,
        private TemporaryPasswordChannel $channel,
    ) {}

    /**
     * @param  array{name: string, email: string, salon_role: string, staff_type?: string|null}  $data
     */
    public function handle(User $actor, Salon $salon, array $data): ProvisionedUser
    {
        $role = SalonRole::from($data['salon_role']);

        if (! $this->roles->canAssign($actor, $salon, $role)) {
            throw new AuthorizationException('You may not grant that role.');
        }

        $staffType = $role === SalonRole::User && ! empty($data['staff_type'])
            ? StaffType::from($data['staff_type'])
            : null;

        $existing = User::where('email', $data['email'])->first();

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

        $this->channel->send($user, $temporaryPassword, 'invite');

        return new ProvisionedUser($user, $temporaryPassword);
    }
}

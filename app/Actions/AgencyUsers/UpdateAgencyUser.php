<?php

namespace App\Actions\AgencyUsers;

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\User;
use App\Support\Permissions\AgencyUserRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Edit an agency user's account (name/email/password), role, and (for
 * agency_users) salon assignments. The actor must have MANAGE authority over
 * the target (which excludes the agency owner as a target — the owner's
 * record is view-only in the users area and self-served via account
 * settings); CHANGING the role additionally needs GRANT authority over both
 * the old and the new role; an actor cannot change their own role (no
 * self-escalation / lockout).
 *
 * THE SHARED-ACCOUNT GUARD (same rule as Staff\UpdateMemberDetails): email
 * is the login identifier account-wide, so when the target also holds salon
 * memberships under ANOTHER agency, an email CHANGE is refused — only the
 * account holder may re-point their login. Re-submitting the unchanged
 * email passes; the guard fires on change, not presence.
 *
 * An admin-set password behaves like the invite/reset flows: it lands
 * hashed with must_change_password raised, so the person picks their own
 * secret at next sign-in and the admin-known value never lingers.
 */
class UpdateAgencyUser
{
    public function __construct(private AgencyUserRoles $roles) {}

    /**
     * @param  array{name: string, email?: string, password?: string|null, agency_role: string, salon_ids?: array<int, int|string>}  $data
     */
    public function handle(User $actor, Agency $agency, User $target, array $data): User
    {
        $email = $data['email'] ?? $target->email; // omitted = unchanged

        if ($target->agency_id !== $agency->id) {
            throw new AuthorizationException('That user is not in this agency.');
        }

        $newRole = AgencyRole::from($data['agency_role']);

        if ($target->agency_role === null || ! $this->roles->canManage($actor, $target->agency_role)) {
            throw new AuthorizationException('You may not manage that user.');
        }

        if ($newRole !== $target->agency_role
            && (! $this->roles->canAssign($actor, $target->agency_role) || ! $this->roles->canAssign($actor, $newRole))) {
            throw new AuthorizationException('You may not change that user\'s role.');
        }

        if ($actor->id === $target->id && $newRole !== $target->agency_role) {
            throw ValidationException::withMessages([
                'agency_role' => __('You cannot change your own role.'),
            ]);
        }

        if (strcasecmp($target->email, $email) !== 0
            && $target->sharedOutsideAgency($agency->id)) {
            throw ValidationException::withMessages([
                'email' => __("This account also belongs to another agency's salon, so its login email can only be changed by the account holder."),
            ]);
        }

        $target->forceFill([
            'name' => $data['name'],
            'email' => $email,
            'agency_role' => $newRole,
        ]);

        if (($data['password'] ?? '') !== '' && $data['password'] !== null) {
            $target->forceFill([
                'password' => $data['password'],
                'must_change_password' => $actor->id !== $target->id,
            ]);
        }

        $target->save();

        if ($newRole === AgencyRole::User) {
            $target->assignedSalons()->sync($this->validSalonIds($agency, $data['salon_ids'] ?? []));
        } else {
            $target->assignedSalons()->detach();
        }

        return $target;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private function validSalonIds(Agency $agency, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(
            $agency->salons()
                ->whereKey($ids)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all()
        );
    }
}

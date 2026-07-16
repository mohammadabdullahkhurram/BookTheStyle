<?php

namespace App\Actions\AgencyUsers;

use App\Models\User;
use App\Support\Permissions\AgencyUserRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Delete an agency-side user account. Authority follows the assignment
 * matrix (AgencyUserRoles::canAssign): the owner may delete admins and
 * users, an admin may delete users only — and since Owner is never an
 * assignable role, NOBODY may delete the agency owner. Same-agency only.
 *
 * Account-level: soft-deletes the user, detaches salon assignments, and
 * removes any salon memberships (agency-provisioned accounts are the
 * agency's to remove; history keeps the user id and resolves withTrashed).
 */
class DeleteAgencyUser
{
    public function __construct(private AgencyUserRoles $roles) {}

    public function handle(User $actor, User $target): void
    {
        if ($target->agency_id === null || $target->agency_id !== $actor->agency_id) {
            throw new AuthorizationException('That user is not in your agency.');
        }

        if ($target->id === $actor->id) {
            throw new AuthorizationException('You cannot delete your own account here.');
        }

        if ($target->agency_role === null || ! $this->roles->canAssign($actor, $target->agency_role)) {
            throw new AuthorizationException('You may not delete that user.');
        }

        DB::transaction(function () use ($target): void {
            $target->assignedSalons()->detach();
            $target->salonMemberships()->delete();
            $target->delete();
        });
    }
}

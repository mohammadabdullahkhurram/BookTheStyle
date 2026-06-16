<?php

namespace App\Actions\AgencyUsers;

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\User;
use App\Support\Permissions\AgencyUserRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Edit an agency user's name, role, and (for agency_users) salon assignments.
 * The actor must have authority over both the current and the new role, the
 * target must belong to the actor's agency, and an actor cannot change their
 * own role (no self-escalation / lockout).
 */
class UpdateAgencyUser
{
    public function __construct(private AgencyUserRoles $roles) {}

    /**
     * @param  array{name: string, agency_role: string, salon_ids?: array<int, int|string>}  $data
     */
    public function handle(User $actor, Agency $agency, User $target, array $data): User
    {
        if ($target->agency_id !== $agency->id) {
            throw new AuthorizationException('That user is not in this agency.');
        }

        $newRole = AgencyRole::from($data['agency_role']);

        if ($target->agency_role === null
            || ! $this->roles->canAssign($actor, $target->agency_role)
            || ! $this->roles->canAssign($actor, $newRole)) {
            throw new AuthorizationException('You may not manage that user.');
        }

        if ($actor->id === $target->id && $newRole !== $target->agency_role) {
            throw ValidationException::withMessages([
                'agency_role' => __('You cannot change your own role.'),
            ]);
        }

        $target->update([
            'name' => $data['name'],
            'agency_role' => $newRole,
        ]);

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

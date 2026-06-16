<?php

namespace App\Actions\Staff;

use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use App\Support\Permissions\SalonStaffRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Edit a staff member's salon role / staff type. The actor must have authority
 * over BOTH the membership's current role and the new role (so a salon_admin
 * can neither touch a salon_owner nor promote anyone to salon_owner). The
 * membership must belong to the resolved salon (anti-IDOR).
 */
class UpdateStaffMembership
{
    public function __construct(private SalonStaffRoles $roles) {}

    /**
     * @param  array{salon_role: string, staff_type?: string|null}  $data
     */
    public function handle(User $actor, Salon $salon, SalonMembership $membership, array $data): SalonMembership
    {
        $this->assertBelongsToSalon($membership, $salon);

        $newRole = SalonRole::from($data['salon_role']);

        if (! $this->roles->canAssign($actor, $salon, $membership->salon_role)
            || ! $this->roles->canAssign($actor, $salon, $newRole)) {
            throw new AuthorizationException('You may not manage that staff member.');
        }

        $staffType = $newRole === SalonRole::User && ! empty($data['staff_type'])
            ? StaffType::from($data['staff_type'])
            : null;

        if ($membership->salon_role === SalonRole::Owner
            && $newRole !== SalonRole::Owner
            && $this->isLastActiveOwner($salon, $membership)) {
            throw ValidationException::withMessages([
                'salon_role' => __('A salon must keep at least one active owner.'),
            ]);
        }

        $membership->update([
            'salon_role' => $newRole,
            'staff_type' => $staffType,
        ]);

        return $membership;
    }

    private function assertBelongsToSalon(SalonMembership $membership, Salon $salon): void
    {
        if ($membership->salon_id !== $salon->id) {
            throw new AuthorizationException('That staff member is not in this salon.');
        }
    }

    private function isLastActiveOwner(Salon $salon, SalonMembership $membership): bool
    {
        return $salon->memberships()
            ->where('salon_role', SalonRole::Owner->value)
            ->where('active', true)
            ->whereKeyNot($membership->id)
            ->doesntExist();
    }
}

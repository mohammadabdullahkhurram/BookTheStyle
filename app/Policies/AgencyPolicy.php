<?php

namespace App\Policies;

use App\Models\Agency;
use App\Models\User;

/**
 * Authorisation for the agency console. Only agency owners/admins operate the
 * agency itself (create/manage salons, manage agency users). Agency users have
 * no console access — they manage only their assigned salons, which is enforced
 * by SalonPolicy + ResolveSalon.
 *
 * Every ability also confirms the user belongs to *this* agency, so an operator
 * of one agency can never act on another.
 */
class AgencyPolicy
{
    private function operatesAgency(User $user, Agency $agency): bool
    {
        return $user->agency_id === $agency->id && $user->isAgencyOperator();
    }

    public function accessConsole(User $user, Agency $agency): bool
    {
        return $this->operatesAgency($user, $agency);
    }

    public function manageSalons(User $user, Agency $agency): bool
    {
        return $this->operatesAgency($user, $agency);
    }

    public function manageUsers(User $user, Agency $agency): bool
    {
        return $this->operatesAgency($user, $agency);
    }
}

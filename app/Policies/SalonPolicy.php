<?php

namespace App\Policies;

use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\User;

/**
 * Authorisation for salon-scoped capabilities (SPEC §3 permission matrix).
 * This is a skeleton: the booking/service/calendar abilities it anticipates
 * are wired to real features in later phases, but the role logic lives here so
 * every server-side check routes through one place.
 *
 * Every ability first confirms the user can reach the salon at all
 * (membership or privileged agency access); changing an id in a request can
 * never grant access to another salon's data.
 */
class SalonPolicy
{
    /**
     * Agency owners/admins implicitly pass any check within their own agency.
     */
    public function before(User $user, string $ability, Salon $salon): ?bool
    {
        if ($user->agency_id === $salon->agency_id && $user->agency_role?->isPrivileged()) {
            return true;
        }

        return null;
    }

    public function view(User $user, Salon $salon): bool
    {
        return $user->operatesSalon($salon) || $user->membershipFor($salon) !== null;
    }

    /**
     * Manage the salon's settings (booking policy, feature flags, branding).
     * Salon owners/admins, or an agency operator authorised for this salon
     * (owner/admin via `before`, or an agency_user assigned to it).
     */
    public function manage(User $user, Salon $salon): bool
    {
        if ($user->operatesSalon($salon)) {
            return true;
        }

        return $user->membershipFor($salon)?->salon_role->isManager() ?? false;
    }

    public function manageStaff(User $user, Salon $salon): bool
    {
        return $this->manage($user, $salon);
    }

    public function manageServices(User $user, Salon $salon): bool
    {
        return $this->manage($user, $salon);
    }

    /**
     * Front-desk-level booking capability: create/edit/cancel any booking,
     * check clients in, and manage clients. Owner/admin (and agency operators)
     * via `manage`, plus front desk. Stylists are NOT included here — they only
     * see their own bookings/clients.
     */
    public function manageBookings(User $user, Salon $salon): bool
    {
        if ($this->manage($user, $salon)) {
            return true;
        }

        return $user->membershipFor($salon)?->staff_type === StaffType::FrontDesk;
    }

    /**
     * May reach the bookings area at all (dashboard/appointments): front desk +
     * managers manage everything; a stylist sees only their own.
     */
    public function accessBookings(User $user, Salon $salon): bool
    {
        return $this->manageBookings($user, $salon)
            || $user->stylistMembershipFor($salon) !== null;
    }

    /**
     * Only the salon owner connects/configures the salon's GHL (SPEC §3).
     */
    public function connectGhl(User $user, Salon $salon): bool
    {
        return $user->membershipFor($salon)?->salon_role === SalonRole::Owner;
    }

    /**
     * View/edit the salon's GoHighLevel connection credentials (Location ID,
     * Calendar ID, Private Integration Token). Allowed for the salon's own
     * owner/admin and — via `before()` — agency owners/admins in this agency.
     * Salon staff (stylist/front-desk) and agency_users are denied: they never
     * see or set the token. (Connection *status* may be surfaced separately.)
     */
    public function manageGhlConnection(User $user, Salon $salon): bool
    {
        return $user->membershipFor($salon)?->salon_role->isManager() ?? false;
    }

    /**
     * Master calendar: owner, admin, and front desk — not stylists.
     */
    public function viewMasterCalendar(User $user, Salon $salon): bool
    {
        $membership = $user->membershipFor($salon);

        if ($membership === null) {
            return false;
        }

        return $membership->salon_role->isManager()
            || $membership->staff_type === StaffType::FrontDesk;
    }
}

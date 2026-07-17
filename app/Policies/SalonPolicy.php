<?php

namespace App\Policies;

use App\Enums\SalonRole;
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
     * Booking capability: create/edit/cancel any booking, check clients in,
     * manage clients. Owner/admin (and agency operators) via `manage`. Since
     * the role remap, managers and front desk ARE salon admins (staff type is
     * functional only), so no type-based special case remains. Staff
     * (stylists) are NOT included — they only see their own bookings/clients.
     */
    public function manageBookings(User $user, Salon $salon): bool
    {
        return $this->manage($user, $salon);
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
     * Create bookings: managers everywhere; a BOOTH-RENTING stylist for their
     * own book (CreateBooking additionally pins every item to them).
     * Employee stylists never — the desk books their clients in.
     */
    public function createBookings(User $user, Salon $salon): bool
    {
        return $this->manageBookings($user, $salon)
            || $user->boothRenterMembershipFor($salon) !== null;
    }

    /**
     * The client directory/profiles: managers see all; a booth renter only
     * the clients THEY have served (the pages force that scope). Employee
     * stylists never.
     */
    public function accessClients(User $user, Salon $salon): bool
    {
        return $this->manageBookings($user, $salon)
            || $user->boothRenterMembershipFor($salon) !== null;
    }

    /**
     * Reports: managers see the salon; a booth renter only their own
     * bookings/revenue/no-shows (the page forces the scope).
     */
    public function viewReports(User $user, Salon $salon): bool
    {
        return $this->manage($user, $salon)
            || $user->boothRenterMembershipFor($salon) !== null;
    }

    /**
     * Connect/configure the salon's GHL: the full admin surface belongs to
     * owner AND admin (SPEC §2) — plus agency operators via `before`.
     */
    public function connectGhl(User $user, Salon $salon): bool
    {
        return $user->membershipFor($salon)?->salon_role->isManager() ?? false;
    }

    /**
     * Create/edit the salon's business + point-of-contact profile (legal name,
     * business email/phone/website, address, primary contact). Allowed for the
     * salon's own owner/admin and — via `before()` — agency owners/admins in
     * this agency. Salon staff (stylist/front-desk) and agency_users may view
     * (per existing screens) but never edit.
     */
    public function manageProfile(User $user, Salon $salon): bool
    {
        return $user->membershipFor($salon)?->salon_role->isManager() ?? false;
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
     * Master calendar: owner and admin (front desk / managers hold the admin
     * role since the remap) — not staff (stylists), who get their own view.
     */
    public function viewMasterCalendar(User $user, Salon $salon): bool
    {
        return $user->membershipFor($salon)?->salon_role->isManager() ?? false;
    }

    /**
     * Delete the salon. Salon ADMINS never can; the salon's own owner can;
     * and — deliberately, via `before()` — agency owners/admins retain the
     * override (the agency must stay able to remove salons from its own
     * platform). NOTE: no in-app delete flow exists today — the live
     * operation is agency-side DEACTIVATION — but the rule is enforced here
     * so any future delete feature inherits it.
     */
    public function delete(User $user, Salon $salon): bool
    {
        return $user->membershipFor($salon)?->salon_role === SalonRole::Owner;
    }
}

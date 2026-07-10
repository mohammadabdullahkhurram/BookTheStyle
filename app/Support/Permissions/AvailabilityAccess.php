<?php

namespace App\Support\Permissions;

use App\Models\Salon;
use App\Models\User;

/**
 * Who may manage a stylist's availability (SPEC §3): a salon owner/admin (or an
 * agency operator authorised for the salon) may edit ANY stylist; a stylist may
 * edit only their OWN; front desk may edit none.
 *
 * This is the single source of truth — actions call canManage() before every
 * mutation, so the UI can never be trusted to bypass it.
 */
class AvailabilityAccess
{
    public function canManage(User $actor, Salon $salon, int $stylistUserId): bool
    {
        // Owner/admin/agency-operator manage any stylist in the salon.
        if ($actor->can('manage', $salon)) {
            return true;
        }

        // A stylist manages only their own availability.
        return $actor->id === $stylistUserId
            && $actor->stylistMembershipFor($salon) !== null;
    }
}

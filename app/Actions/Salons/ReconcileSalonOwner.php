<?php

namespace App\Actions\Salons;

use App\Actions\Staff\InviteStaff;
use App\Enums\BookingStatus;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use App\Support\ProvisionedUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Owner details on the salon profile are the SINGLE SOURCE OF TRUTH for
 * who the owner is. Every profile save runs this reconciliation:
 *
 *  - no owner exists          → provision one from the details (the standard
 *                               engine + mailables; fixes pre-provisioning
 *                               salons the moment the profile is saved)
 *  - same email, new details  → update the owner's name/phone (only an
 *                               agency operator or the owner themself may;
 *                               a salon manager's save must leave them
 *                               untouched)
 *  - DIFFERENT email          → an ownership TRANSFER, delegated to
 *                               SetSalonOwner (agency owner only): the old
 *                               owner is demoted, the singleton asserted —
 *                               never two owners
 *
 * It also applies the "owner is also a stylist" flag (staff_type on the
 * owner membership — the long-standing bookability mechanism, NOT a second
 * role): checking makes the owner bookable with full owner rights;
 * unchecking is refused while they still have upcoming bookings, so nothing
 * is ever orphaned.
 */
class ReconcileSalonOwner
{
    public function __construct(
        private InviteStaff $invites,
        private SetSalonOwner $transfer,
    ) {}

    /**
     * @return ProvisionedUser|null the provisioning/transfer result when one ran
     */
    public function handle(User $actor, Salon $salon, bool $ownerIsStylist): ?ProvisionedUser
    {
        if (blank($salon->contact_email) || blank($salon->contact_name)) {
            return null;
        }

        $current = $salon->memberships()
            ->where('salon_role', SalonRole::Owner->value)
            ->where('active', true)
            ->with('user')
            ->first();

        // No owner: complete the invariant the profile copy promises. Safe
        // for any profile-save actor — the contact person was always the
        // designated owner; this grants nothing to the actor themself.
        if ($current === null || $current->user === null) {
            return DB::transaction(function () use ($actor, $salon, $ownerIsStylist): ProvisionedUser {
                $result = $this->invites->provisionOwner($salon, [
                    'name' => (string) $salon->contact_name,
                    'email' => (string) $salon->contact_email,
                    'phone' => $salon->contact_phone ?: null,
                ]);

                $membership = $salon->memberships()
                    ->where('user_id', $result->user->id)->firstOrFail();
                $this->applyBookable($actor, $salon, $membership, $ownerIsStylist, isNew: true);

                return $result;
            });
        }

        if (strcasecmp($current->user->email, (string) $salon->contact_email) === 0) {
            $this->syncDetails($actor, $salon, $current);
            $this->applyBookable($actor, $salon, $current, $ownerIsStylist, isNew: false);

            return null;
        }

        // Email points at a DIFFERENT person: a deliberate ownership change.
        // SetSalonOwner enforces its own rule (agency owner only), demotes
        // the incumbent, and asserts exactly one owner remains.
        $result = $this->transfer->handle($actor, $salon, [
            'name' => (string) $salon->contact_name,
            'email' => (string) $salon->contact_email,
            'phone' => $salon->contact_phone ?: null,
        ]);

        $membership = $salon->memberships()
            ->where('user_id', $result->user->id)->firstOrFail();
        $this->applyBookable($actor, $salon, $membership, $ownerIsStylist, isNew: true);

        return $result;
    }

    /**
     * Name/phone follow the profile — but only an agency operator of this
     * salon's agency, or the owner themself, may change the owner's record.
     * Anyone else's save must simply not touch them.
     */
    private function syncDetails(User $actor, Salon $salon, SalonMembership $current): void
    {
        $owner = $current->user;
        $newPhone = $salon->contact_phone ?: null;

        if ($owner->name === $salon->contact_name && $owner->phone === $newPhone) {
            return;
        }

        if (! $this->mayEditOwner($actor, $salon, $owner)) {
            throw ValidationException::withMessages([
                'contact_name' => __('Owner details belong to the owner — only they or your agency can change them.'),
            ]);
        }

        $owner->forceFill([
            'name' => (string) $salon->contact_name,
            'phone' => $newPhone,
        ])->save();
    }

    /**
     * The owner-who-cuts-hair switch, profile edition: staff_type 'stylist'
     * on the owner membership makes them bookable while keeping full owner
     * rights. Unchecking with upcoming bookings is refused — reassign or
     * complete them first, so no booking is ever orphaned.
     */
    private function applyBookable(User $actor, Salon $salon, SalonMembership $membership, bool $ownerIsStylist, bool $isNew): void
    {
        $currentlyBookable = $membership->staff_type === StaffType::Stylist;

        if ($currentlyBookable === $ownerIsStylist) {
            return;
        }

        if (! $isNew && ! $this->mayEditOwner($actor, $salon, $membership->user)) {
            throw ValidationException::withMessages([
                'owner_is_stylist' => __('Only the owner or your agency can change whether the owner takes bookings.'),
            ]);
        }

        if (! $ownerIsStylist) {
            $upcoming = $salon->bookings()
                ->where('status', '!=', BookingStatus::Cancelled->value)
                ->whereHas('items', fn ($q) => $q
                    ->where('stylist_id', $membership->user_id)
                    ->where('starts_at', '>', now()))
                ->count();

            if ($upcoming > 0) {
                throw ValidationException::withMessages([
                    'owner_is_stylist' => trans_choice(
                        'The owner still has :count upcoming appointment — reassign or cancel it before removing them from bookings.|The owner still has :count upcoming appointments — reassign or cancel them before removing them from bookings.',
                        $upcoming, ['count' => $upcoming]),
                ]);
            }
        }

        $membership->update(['staff_type' => $ownerIsStylist ? StaffType::Stylist : null]);
    }

    private function mayEditOwner(User $actor, Salon $salon, User $owner): bool
    {
        return $actor->id === $owner->id
            || ($actor->isAgencyOperator() && $actor->agency_id === $salon->agency_id);
    }
}

<?php

namespace App\Actions\Staff;

use App\Actions\Bookings\PurgeBookings;
use App\Enums\SalonRole;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use App\Support\Permissions\SalonStaffRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PERMANENTLY delete a staff member from a salon — record AND history gone.
 * This is the destructive sibling of DeleteStaffUser (which removes the
 * membership but keeps booking history) and of deactivation (the archive
 * path). Everything of theirs in THIS salon goes: their appointments
 * (FK-safe, GHL-cancelled where synced), availability, time off, stylist
 * profile, service assignments, membership. The ACCOUNT is force-deleted
 * only when nothing else references it — a person working at two salons
 * purged from one keeps logging in to the other; cross-salon data is
 * untouched by construction.
 *
 * Gates: salon owner + agency owner/admin only (SalonPolicy::hardDelete —
 * managers/stylists cannot), PLUS role authority over the target
 * (SalonStaffRoles::canManage). Never yourself, never the last active
 * owner (transfer first), never in the demo. Future bookings are never
 * deleted silently: open upcoming appointments require the caller's
 * explicit acknowledgment or the whole delete refuses.
 */
class PurgeStaffMember
{
    public function __construct(
        private SalonStaffRoles $roles,
        private PurgeBookings $purge,
    ) {}

    /**
     * @return bool whether the ACCOUNT was deleted too (vs this salon only)
     */
    public function handle(User $actor, Salon $salon, SalonMembership $membership, bool $acknowledgedUpcoming = false): bool
    {
        if ($membership->salon_id !== $salon->id) {
            throw new AuthorizationException('That staff member is not in this salon.');
        }

        if ($salon->is_demo || ! $actor->can('hardDelete', $salon)) {
            throw new AuthorizationException('You may not permanently delete staff here.');
        }

        if ($membership->user_id === $actor->id) {
            throw new AuthorizationException('You cannot delete yourself from the staff screen. Account deletion lives in your account settings.');
        }

        if (! $this->roles->canManage($actor, $salon, $membership->salon_role)) {
            throw new AuthorizationException('You may not manage that staff member.');
        }

        if ($membership->salon_role === SalonRole::Owner && $this->isLastActiveOwner($salon, $membership)) {
            throw ValidationException::withMessages([
                'owner' => __('This salon needs an owner — transfer ownership first.'),
            ]);
        }

        $bookings = self::bookingsOf($salon, $membership->user_id);

        if (PurgeBookings::upcoming($bookings)->isNotEmpty() && ! $acknowledgedUpcoming) {
            throw ValidationException::withMessages([
                'acknowledge' => __('This staff member has upcoming appointments — confirm you understand they will be permanently deleted.'),
            ]);
        }

        $user = $membership->user;

        return DB::transaction(function () use ($salon, $membership, $user, $bookings): bool {
            $this->purge->handle($salon, $bookings);

            Availability::forSalon($salon)->where('user_id', $user->id)->delete();
            DB::table('time_off')->where('salon_id', $salon->id)->where('user_id', $user->id)->delete();
            DB::table('stylist_profiles')->where('salon_id', $salon->id)->where('user_id', $user->id)->delete();
            DB::table('service_stylist')->where('salon_id', $salon->id)->where('user_id', $user->id)->delete();
            $membership->delete();

            $accountRemovable = $user->agency_role === null
                && ! $user->salonMemberships()->exists();

            if ($accountRemovable) {
                $user->forceDelete(); // permanent — the deleted event still strips passkeys
            }

            return $accountRemovable;
        });
    }

    /**
     * Every booking in THIS salon carrying the member's items — their
     * blast radius, shared with the confirm UI.
     *
     * @return Collection<int, Booking>
     */
    public static function bookingsOf(Salon $salon, int $userId): Collection
    {
        return Booking::query()
            ->where('salon_id', $salon->id)
            ->whereHas('items', fn ($q) => $q->where('stylist_id', $userId))
            ->with(['items', 'client:id,name'])
            ->get();
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

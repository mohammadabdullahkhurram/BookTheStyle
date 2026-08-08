<?php

namespace App\Actions\Staff;

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
 * SOLO delete of a staff member from a salon: the member is removed —
 * their APPOINTMENTS ARE KEPT, upcoming ones included (they render with
 * the stylist's name via the withTrashed relation, and the health check's
 * integrity spot check flags any upcoming ones for reassignment). What
 * goes is theirs alone in THIS salon: availability, time off, stylist
 * profile, service assignments (pure link rows), membership. The ACCOUNT
 * soft-deletes only when nothing else references it — a person working
 * at two salons deleted from one keeps logging in to the other; it is
 * never force-deleted, because kept appointments snapshot their name
 * through the soft-deleted row.
 *
 * Gates: salon owner + agency owner/admin only (SalonPolicy::hardDelete),
 * PLUS role authority over the target (SalonStaffRoles::canManage).
 * Never yourself, never the last active owner (transfer first), never in
 * the demo. Deactivation stays the archive path.
 */
class PurgeStaffMember
{
    public function __construct(private SalonStaffRoles $roles) {}

    /**
     * @return bool whether the ACCOUNT was removed too (vs this salon only)
     */
    public function handle(User $actor, Salon $salon, SalonMembership $membership): bool
    {
        if ($membership->salon_id !== $salon->id) {
            throw new AuthorizationException('That staff member is not in this salon.');
        }

        if ($salon->is_demo || ! $actor->can('hardDelete', $salon)) {
            throw new AuthorizationException('You may not delete staff here.');
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

        $user = $membership->user;

        return DB::transaction(function () use ($salon, $membership, $user): bool {
            Availability::forSalon($salon)->where('user_id', $user->id)->delete();
            DB::table('time_off')->where('salon_id', $salon->id)->where('user_id', $user->id)->delete();
            DB::table('stylist_profiles')->where('salon_id', $salon->id)->where('user_id', $user->id)->delete();
            DB::table('service_stylist')->where('salon_id', $salon->id)->where('user_id', $user->id)->delete();
            $membership->delete();

            $accountRemovable = $user->agency_role === null
                && ! $user->salonMemberships()->exists();

            if ($accountRemovable) {
                $user->delete(); // soft — kept appointments keep their name
            }

            return $accountRemovable;
        });
    }

    /**
     * Every booking in THIS salon carrying the member's items — shown in
     * the confirm UI as what will be KEPT.
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

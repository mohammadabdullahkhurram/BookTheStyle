<?php

namespace App\Actions\Demo;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hard-delete ONE demo salon and everything under it: the salon row (its
 * children — memberships, services, bookings, clients, availability —
 * cascade via their salon_id foreign keys) plus the demo accounts, force-
 * deleted so no soft-deleted rows accumulate (inodes and disk are finite
 * on this host). Refuses non-demo salons outright — this is the one place
 * in the codebase that hard-deletes a salon, and it must never be
 * pointable at a real one.
 */
class DeleteDemoSalon
{
    public function handle(Salon $salon): void
    {
        if (! $salon->is_demo) {
            throw new \RuntimeException('Refusing to delete a non-demo salon.');
        }

        DB::transaction(function () use ($salon): void {
            $userIds = $salon->memberships()->pluck('user_id');

            $salon->delete();

            // Demo accounts exist only for their salon; delete the ones with
            // no other membership (paranoia — demo users never have any).
            User::withTrashed()
                ->whereIn('id', $userIds)
                ->whereDoesntHave('salonMemberships')
                ->whereNull('agency_role')
                ->get()
                ->each(fn (User $user) => $user->forceDelete());
        });
    }
}

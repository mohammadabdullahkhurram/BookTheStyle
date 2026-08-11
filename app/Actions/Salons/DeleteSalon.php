<?php

namespace App\Actions\Salons;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PERMANENTLY delete a REAL salon and everything under it — the most
 * destructive action in the app. Agency owner/admin of THIS salon's agency
 * only, never salon roles, never delegated agency_users; demo salons are
 * refused (their lifecycle belongs to the demo sweep/reset). The archive
 * path remains DEACTIVATION (SetSalonActive) — recoverable, no data loss;
 * this action exists for genuine removal and requires the caller to have
 * collected a typed-name confirmation first (the UI's job; the action
 * takes the typed value and re-verifies it server-side).
 *
 * FK order: every salon-owned table (memberships, services + pivots,
 * clients + notes, bookings + items + status events, availability, time
 * off, stylist profiles, widgets, GHL connection, webhook events, health
 * runs) carries a cascading salon_id foreign key, so deleting the salon
 * row removes them atomically at the database layer — the same mechanism
 * the demo sweep has used in production since launch. Member ACCOUNTS are
 * users, not salon-owned rows: accounts left with no other membership and
 * no agency role are soft-deleted (recoverable, names preserved on any
 * cross-salon history); accounts used elsewhere are untouched. Strictly
 * scoped to the one salon; the hostname is NOT touched at runtime —
 * subdomains live in hPanel and are cleaned up by a human.
 */
class DeleteSalon
{
    public function handle(User $actor, Salon $salon, string $confirmName): void
    {
        if ($salon->is_demo) {
            throw new AuthorizationException('Demo salons are managed by the demo lifecycle.');
        }

        if (! $actor->isAgencyOperator() || $actor->agency_id !== $salon->agency_id) {
            throw new AuthorizationException('Only this agency\'s owner or admins may delete a salon.');
        }

        if (trim($confirmName) !== $salon->name) {
            throw ValidationException::withMessages([
                'confirmName' => __('Type the salon\'s exact name to confirm.'),
            ]);
        }

        DB::transaction(function () use ($salon): void {
            $memberIds = $salon->memberships()->pluck('user_id');

            $salon->delete(); // salon-owned rows cascade via their FKs

            User::query()
                ->whereIn('id', $memberIds)
                ->whereDoesntHave('salonMemberships')
                ->whereNull('agency_role')
                ->get()
                ->each(fn (User $user) => $user->delete()); // soft — recoverable
        });
    }

    /**
     * The blast radius shown in the confirmation: what deleting this salon
     * removes, counted live.
     *
     * @return array<string, int>
     */
    public static function blastRadius(Salon $salon): array
    {
        return [
            'staff' => $salon->memberships()->count(),
            'services' => $salon->services()->withTrashed()->count(),
            'clients' => $salon->clients()->withTrashed()->count(),
            'appointments' => $salon->bookings()->count(),
            'widgets' => $salon->widgets()->count(),
        ];
    }
}

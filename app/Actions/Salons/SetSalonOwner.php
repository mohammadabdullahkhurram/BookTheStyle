<?php

namespace App\Actions\Salons;

use App\Actions\Staff\InviteStaff;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\User;
use App\Support\ProvisionedUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assign or transfer a salon's ownership — an AGENCY-level action (SPEC §2):
 * only the AGENCY OWNER of the salon's agency may do it, from the agency
 * console. Salon managers never can; owners are never granted through staff
 * invites (InviteStaff refuses the role outright).
 *
 * The one-owner rule is unbreakable here: assigning demotes any current
 * owner in the same transaction — a bookable ex-owner becomes a Stylist
 * (keeps their calendar column), otherwise a Manager — and the action ends
 * by ASSERTING exactly one active owner remains, throwing rather than
 * committing a second.
 *
 * Two modes: promote an existing member of the salon (membership_id), or
 * provision a brand-new owner from contact details — through the same
 * provisioning engine and mailables staff invites use (temp password shown
 * once, branded mails; an existing/deleted account is linked/restored).
 */
class SetSalonOwner
{
    public function __construct(private InviteStaff $invites) {}

    /**
     * @param  array{membership_id?: int, name?: string, email?: string, phone?: string|null}  $data
     */
    public function handle(User $actor, Salon $salon, array $data): ProvisionedUser
    {
        if ($actor->agency_id === null
            || $actor->agency_id !== $salon->agency_id
            || $actor->agency_role !== AgencyRole::Owner) {
            throw new AuthorizationException("Only the agency owner may assign a salon's owner.");
        }

        return DB::transaction(function () use ($salon, $data): ProvisionedUser {
            // Re-assigning the incumbent is a no-op, never a demote-then-
            // promote (which would strip a bookable owner's flag in passing).
            $current = $salon->memberships()
                ->where('salon_role', SalonRole::Owner->value)
                ->with('user:id,email')
                ->first();
            $except = match (true) {
                $current === null => null,
                isset($data['membership_id']) && (int) $data['membership_id'] === $current->id => $current->id,
                isset($data['email']) && $current->user !== null && strcasecmp($current->user->email, (string) $data['email']) === 0 => $current->id,
                default => null,
            };

            $this->demoteCurrentOwner($salon, except: $except);

            $result = isset($data['membership_id'])
                ? $this->promoteMember($salon, (int) $data['membership_id'])
                : $this->invites->provisionOwner($salon, [
                    'name' => (string) ($data['name'] ?? ''),
                    'email' => (string) ($data['email'] ?? ''),
                    'phone' => $data['phone'] ?? null,
                ]);

            $this->assertExactlyOneOwner($salon);

            return $result;
        });
    }

    /**
     * The previous owner keeps working here, one rung down: bookable owners
     * become Stylists (their calendar column and availability survive),
     * everyone else becomes a Manager. Never deleted, never deactivated.
     */
    private function demoteCurrentOwner(Salon $salon, ?int $except): void
    {
        $salon->memberships()
            ->where('salon_role', SalonRole::Owner->value)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->get()
            ->each(function ($membership): void {
                $membership->update($membership->staff_type === StaffType::Stylist
                    ? ['salon_role' => SalonRole::Stylist]
                    : ['salon_role' => SalonRole::Manager, 'staff_type' => null]);
            });
    }

    private function promoteMember(Salon $salon, int $membershipId): ProvisionedUser
    {
        $membership = $salon->memberships()->whereKey($membershipId)->firstOrFail();

        // Promotion keeps the bookability flag — a promoted stylist is the
        // owner-who-cuts-hair case, exactly what staff_type exists for.
        $membership->update(['salon_role' => SalonRole::Owner, 'active' => true]);

        return new ProvisionedUser($membership->user, temporaryPassword: null, existing: true);
    }

    private function assertExactlyOneOwner(Salon $salon): void
    {
        $count = $salon->memberships()
            ->where('salon_role', SalonRole::Owner->value)
            ->where('active', true)
            ->count();

        if ($count !== 1) {
            throw ValidationException::withMessages([
                'owner' => __('Ownership assignment would leave :count owners — refused.', ['count' => $count]),
            ]);
        }
    }
}

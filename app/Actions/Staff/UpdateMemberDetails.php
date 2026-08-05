<?php

namespace App\Actions\Staff;

use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Agency-operator edit of a member's ACCOUNT details (name/email/phone) from
 * the salon Users page. Only a privileged agency operator of the salon's own
 * agency may call it; the membership must belong to the resolved salon
 * (anti-IDOR).
 *
 * THE SHARED-ACCOUNT GUARD: one login can hold memberships under different
 * agencies, and email is the login identifier — changing it changes where
 * the person signs in EVERYWHERE. So when the target account extends beyond
 * this agency (User::sharedOutsideAgency), an email CHANGE is refused
 * server-side: only the account holder may re-point their login, via their
 * own account settings. Re-submitting the unchanged email passes — the
 * guard fires on change, not presence. Name/phone are not login-critical
 * and stay editable (the UI warns that they apply account-wide).
 */
class UpdateMemberDetails
{
    /**
     * @param  array{name: string, email: string, phone?: string|null}  $data
     */
    public function handle(User $actor, Salon $salon, SalonMembership $membership, array $data): User
    {
        if ($membership->salon_id !== $salon->id) {
            throw new AuthorizationException('That staff member is not in this salon.');
        }

        if (! $actor->isAgencyOperator() || $actor->agency_id !== $salon->agency_id) {
            throw new AuthorizationException("Only this salon's agency may edit member details.");
        }

        $user = $membership->user;

        if (strcasecmp($user->email, $data['email']) !== 0
            && $user->sharedOutsideAgency($salon->agency_id)) {
            throw ValidationException::withMessages([
                'email' => __("This account also belongs to another agency's salon, so its login email can only be changed by the account holder."),
            ]);
        }

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => ($data['phone'] ?? null) ?: null,
        ])->save();

        return $user;
    }
}

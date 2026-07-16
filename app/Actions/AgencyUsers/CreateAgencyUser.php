<?php

namespace App\Actions\AgencyUsers;

use App\Enums\AgencyRole;
use App\Mail\AccountCreatedMail;
use App\Models\Agency;
use App\Models\User;
use App\Support\Notifications\TemporaryPasswordChannel;
use App\Support\Permissions\AgencyUserRoles;
use App\Support\ProvisionedUser;
use App\Support\TemporaryPassword;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Create an agency user. Only an agency_owner may create owners/admins; an
 * agency_admin may create agency_users only — enforced here, server-side. New
 * users get a temporary password + forced change; agency_users may be scoped to
 * specific salons (their access scope).
 */
class CreateAgencyUser
{
    public function __construct(
        private AgencyUserRoles $roles,
        private TemporaryPasswordChannel $channel,
    ) {}

    /**
     * @param  array{name: string, email: string, agency_role: string, salon_ids?: array<int, int|string>}  $data
     */
    public function handle(User $actor, Agency $agency, array $data): ProvisionedUser
    {
        $role = AgencyRole::from($data['agency_role']);

        if (! $this->roles->canAssign($actor, $role)) {
            throw new AuthorizationException('You may not grant that agency role.');
        }

        $temporaryPassword = TemporaryPassword::generate();

        $user = DB::transaction(function () use ($agency, $data, $role, $temporaryPassword): User {
            // Re-creating a DELETED person's account restores it — history
            // keeps its owner, and the unique email blocks a fresh row.
            $trashed = User::onlyTrashed()->where('email', $data['email'])->first();

            if ($trashed !== null) {
                $trashed->restore();
                $trashed->forceFill([
                    'name' => $data['name'],
                    'password' => $temporaryPassword,
                    'agency_id' => $agency->id,
                    'agency_role' => $role,
                    'must_change_password' => true,
                ])->save();

                $user = $trashed;
            } else {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $temporaryPassword,
                    'agency_id' => $agency->id,
                    'agency_role' => $role,
                    'must_change_password' => true,
                    'email_verified_at' => now(),
                ]);
            }

            if ($role === AgencyRole::User) {
                $user->assignedSalons()->sync(
                    $this->validSalonIds($agency, $data['salon_ids'] ?? []),
                );
            }

            return $user;
        });

        // Welcome email + the temporary password (separate email, queued,
        // fail-safe — see MailTemporaryPasswordChannel).
        rescue(fn () => Mail::to($user->email)->send(
            new AccountCreatedMail($user->name, $agency->name, route('login')),
        ));
        $this->channel->send($user, $temporaryPassword, 'invite');

        return new ProvisionedUser($user, $temporaryPassword);
    }

    /**
     * Keep only salon ids that belong to this agency (cross-agency safe).
     *
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private function validSalonIds(Agency $agency, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(
            $agency->salons()
                ->whereKey($ids)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all()
        );
    }
}

<?php

namespace App\Actions\Stylists;

use App\Models\Salon;
use App\Models\StylistProfile;
use App\Models\User;
use App\Support\Permissions\AvailabilityAccess;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Create or update a stylist's lightweight profile (bio) for a salon. Uses the
 * same access rule as availability: own (stylist) or any (owner/admin).
 */
class UpdateStylistProfile
{
    public function __construct(private AvailabilityAccess $access) {}

    public function handle(User $actor, Salon $salon, int $stylistUserId, ?string $bio): StylistProfile
    {
        if (! $this->access->canManage($actor, $salon, $stylistUserId)) {
            throw new AuthorizationException('You may not manage this stylist\'s profile.');
        }

        return StylistProfile::updateOrCreate(
            ['salon_id' => $salon->id, 'user_id' => $stylistUserId],
            ['bio' => $bio],
        );
    }
}

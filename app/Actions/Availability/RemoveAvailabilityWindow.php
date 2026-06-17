<?php

namespace App\Actions\Availability;

use App\Models\Availability;
use App\Models\Salon;
use App\Models\User;
use App\Support\Permissions\AvailabilityAccess;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Remove a weekly availability window. The window must belong to the active
 * salon, and the actor must be allowed to manage its stylist.
 */
class RemoveAvailabilityWindow
{
    public function __construct(private AvailabilityAccess $access) {}

    public function handle(User $actor, Salon $salon, Availability $availability): void
    {
        if ($availability->salon_id !== $salon->id) {
            throw new AuthorizationException('That window is not in this salon.');
        }

        if (! $this->access->canManage($actor, $salon, $availability->user_id)) {
            throw new AuthorizationException('You may not manage this stylist\'s availability.');
        }

        $availability->delete();
    }
}

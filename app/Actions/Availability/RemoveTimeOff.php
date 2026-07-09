<?php

namespace App\Actions\Availability;

use App\Jobs\SyncAvailabilityToGhl;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Models\User;
use App\Support\Permissions\AvailabilityAccess;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Remove a one-off time-off block. Must belong to the active salon and the
 * actor must be allowed to manage its stylist.
 */
class RemoveTimeOff
{
    public function __construct(private AvailabilityAccess $access) {}

    public function handle(User $actor, Salon $salon, TimeOff $timeOff): void
    {
        if ($timeOff->salon_id !== $salon->id) {
            throw new AuthorizationException('That time off is not in this salon.');
        }

        if (! $this->access->canManage($actor, $salon, $timeOff->user_id)) {
            throw new AuthorizationException('You may not manage this stylist\'s availability.');
        }

        $timeOff->delete();

        // Mirror the change into GHL (the date-specific override goes away).
        SyncAvailabilityToGhl::queueForStylist($salon, $timeOff->user_id);
    }
}

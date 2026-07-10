<?php

namespace App\Actions\Availability;

use App\Jobs\SyncAvailabilityToGhl;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Models\User;
use App\Support\Permissions\AvailabilityAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Add a one-off time-off block for a stylist (overrides the weekly schedule).
 */
class AddTimeOff
{
    public function __construct(private AvailabilityAccess $access) {}

    /**
     * @param  array{kind?: string, note?: string|null, starts_at: string, ends_at: string}  $data
     */
    public function handle(User $actor, Salon $salon, int $stylistUserId, array $data): TimeOff
    {
        if (! $this->access->canManage($actor, $salon, $stylistUserId)) {
            throw new AuthorizationException('You may not manage this stylist\'s availability.');
        }

        if (! $salon->stylistUsers()->whereKey($stylistUserId)->exists()) {
            throw ValidationException::withMessages([
                'stylist' => __('That person is not an active stylist in this salon.'),
            ]);
        }

        $kind = $data['kind'] ?? TimeOff::KIND_OFF;

        if (! in_array($kind, [TimeOff::KIND_OFF, TimeOff::KIND_HOURS], true)) {
            throw ValidationException::withMessages(['kind' => __('Invalid entry kind.')]);
        }

        // Input is salon-local wall time; the model stores it as a UTC instant.
        $start = CarbonImmutable::parse($data['starts_at'], $salon->timezone);
        $end = CarbonImmutable::parse($data['ends_at'], $salon->timezone);

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages([
                'ends_at' => __('The end must be after the start.'),
            ]);
        }

        $timeOff = TimeOff::create([
            'salon_id' => $salon->id,
            'user_id' => $stylistUserId,
            'kind' => $kind,
            'note' => $data['note'] ?? null,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);

        // Mirror the change into GHL so its AI stops offering these times.
        SyncAvailabilityToGhl::queueForStylist($salon, $stylistUserId);

        return $timeOff;
    }
}

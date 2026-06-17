<?php

namespace App\Actions\Availability;

use App\Enums\TimeOffType;
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
     * @param  array{type: string, note?: string|null, starts_at: string, ends_at: string}  $data
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

        $type = TimeOffType::from($data['type']);
        // Input is salon-local wall time; the model stores it as a UTC instant.
        $start = CarbonImmutable::parse($data['starts_at'], $salon->timezone);
        $end = CarbonImmutable::parse($data['ends_at'], $salon->timezone);

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages([
                'ends_at' => __('The end must be after the start.'),
            ]);
        }

        return TimeOff::create([
            'salon_id' => $salon->id,
            'user_id' => $stylistUserId,
            'type' => $type,
            'note' => $data['note'] ?? null,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);
    }
}

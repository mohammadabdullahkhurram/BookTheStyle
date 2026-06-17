<?php

namespace App\Actions\Availability;

use App\Enums\AvailabilityKind;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\User;
use App\Support\Permissions\AvailabilityAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Add one weekly availability window (working hours or a break) for a stylist.
 * Enforces, server-side: the actor may manage this stylist (own or manager),
 * the target is an active stylist of the salon, the window has a positive
 * duration within the day, and it doesn't overlap an existing window of the
 * same kind on the same weekday.
 */
class AddAvailabilityWindow
{
    public function __construct(private AvailabilityAccess $access) {}

    /**
     * @param  array{weekday: int, kind: string, start_minute: int, end_minute: int}  $data
     */
    public function handle(User $actor, Salon $salon, int $stylistUserId, array $data): Availability
    {
        if (! $this->access->canManage($actor, $salon, $stylistUserId)) {
            throw new AuthorizationException('You may not manage this stylist\'s availability.');
        }

        if (! $salon->stylistUsers()->whereKey($stylistUserId)->exists()) {
            throw ValidationException::withMessages([
                'stylist' => __('That person is not an active stylist in this salon.'),
            ]);
        }

        $kind = AvailabilityKind::from($data['kind']);
        $weekday = (int) $data['weekday'];
        $start = (int) $data['start_minute'];
        $end = (int) $data['end_minute'];

        if ($weekday < 0 || $weekday > 6) {
            throw ValidationException::withMessages(['weekday' => __('Invalid weekday.')]);
        }

        if ($start < 0 || $end > 1440 || $end <= $start) {
            throw ValidationException::withMessages([
                'end_minute' => __('The end time must be after the start time.'),
            ]);
        }

        // No overlap with an existing window of the same kind on the same day.
        $overlaps = Availability::query()
            ->where('salon_id', $salon->id)
            ->where('user_id', $stylistUserId)
            ->where('weekday', $weekday)
            ->where('kind', $kind->value)
            ->where('start_minute', '<', $end)
            ->where('end_minute', '>', $start)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'end_minute' => __('This window overlaps an existing :kind window on that day.', ['kind' => $kind->label()]),
            ]);
        }

        return Availability::create([
            'salon_id' => $salon->id,
            'user_id' => $stylistUserId,
            'weekday' => $weekday,
            'kind' => $kind,
            'start_minute' => $start,
            'end_minute' => $end,
        ]);
    }
}

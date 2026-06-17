<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Move a booking through its lifecycle (booked → arrived → in_service →
 * completed, plus cancelled / no_show). Transitions are enforced server-side
 * against BookingStatus's allowed map, the booking must belong to the active
 * salon (anti-IDOR), and the actor must be allowed to manage it (front-desk
 * level, or a stylist on one of its items).
 */
class TransitionBookingStatus
{
    public function handle(User $actor, Salon $salon, Booking $booking, BookingStatus $to): Booking
    {
        if ($booking->salon_id !== $salon->id) {
            throw new AuthorizationException('That booking is not in this salon.');
        }

        if (! $this->mayManage($actor, $salon, $booking)) {
            throw new AuthorizationException('You may not manage that booking.');
        }

        $from = $booking->status;

        if ($from === $to) {
            return $booking;
        }

        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => __('Cannot move a booking from :from to :to.', ['from' => $from->label(), 'to' => $to->label()]),
            ]);
        }

        $booking->update(['status' => $to]);
        $booking->statusEvents()->create([
            'salon_id' => $salon->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor->id,
        ]);

        return $booking;
    }

    private function mayManage(User $actor, Salon $salon, Booking $booking): bool
    {
        if ($actor->can('manageBookings', $salon)) {
            return true;
        }

        // A stylist may manage a booking they are assigned to.
        return $actor->stylistMembershipFor($salon) !== null
            && $booking->items()->where('stylist_id', $actor->id)->exists();
    }
}

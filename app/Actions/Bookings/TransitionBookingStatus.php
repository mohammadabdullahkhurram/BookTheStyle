<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\User;
use App\Services\Ghl\GhlStatusMap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Move a booking through its lifecycle (booked → arrived → in_service →
 * completed, plus cancelled / no_show). Transitions are enforced server-side
 * against BookingStatus's allowed map, the booking must belong to the active
 * salon (anti-IDOR), and the actor must be allowed to manage it.
 *
 * Check-in / status management is a front-desk-level capability: salon
 * owner, salon admin, and front-desk staff only. Stylists do NOT change
 * appointment status — even on their own bookings.
 */
class TransitionBookingStatus
{
    public function handle(User $actor, Salon $salon, Booking $booking, BookingStatus $to): Booking
    {
        if ($booking->salon_id !== $salon->id) {
            throw new AuthorizationException('That booking is not in this salon.');
        }

        // Managers everywhere; a BOOTH-RENTING stylist runs their own book and
        // may manage the status of bookings that carry THEIR items. Employee
        // stylists never change status, own or otherwise.
        if (! $actor->can('manageBookings', $salon)) {
            $ownBoothBooking = $actor->boothRenterMembershipFor($salon) !== null
                && $booking->items()->where('stylist_id', $actor->id)->exists();

            if (! $ownBoothBooking) {
                throw new AuthorizationException('You may not manage that booking.');
            }
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

        // Mirror to GHL whenever the mapped appointment status actually
        // changes (cancelled / no-show / completed); app-only lifecycle moves
        // (arrived, in service) map to the same GHL status and stay local.
        if (GhlStatusMap::toGhl($to) !== GhlStatusMap::toGhl($from)) {
            SyncBookingToGhl::queueFor($booking);
        }

        return $booking;
    }
}

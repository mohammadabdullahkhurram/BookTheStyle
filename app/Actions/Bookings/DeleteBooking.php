<?php

namespace App\Actions\Bookings;

use App\Models\Booking;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * PERMANENTLY delete one appointment — the record and its history are gone.
 * Distinct from cancelling (TransitionBookingStatus), which keeps the row
 * as history; this is for genuine removal. Gated to the salon owner +
 * agency owner/admin (SalonPolicy::hardDelete), scoped to the active salon,
 * never in the demo. If the appointment ever reached GHL it is cancelled
 * over there too (PurgeBookings dispatches the id-carrying job).
 */
class DeleteBooking
{
    public function __construct(private PurgeBookings $purge) {}

    public function handle(User $actor, Salon $salon, Booking $booking): void
    {
        if ($booking->salon_id !== $salon->id) {
            throw new AuthorizationException('That booking is not in this salon.');
        }

        if ($salon->is_demo || ! $actor->can('hardDelete', $salon)) {
            throw new AuthorizationException('You may not permanently delete appointments here.');
        }

        DB::transaction(function () use ($salon, $booking): void {
            $this->purge->handle($salon, collect([$booking->load('items')]));
        });
    }
}

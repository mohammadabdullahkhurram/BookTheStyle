<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Jobs\CancelGhlAppointmentRemotely;
use App\Models\Booking;
use App\Models\Salon;
use Illuminate\Support\Collection;

/**
 * The one place bookings are HARD-deleted (permanent removal, history
 * included — "cancel" is TransitionBookingStatus). FK-safe order: items,
 * status events, and the GHL-appointment bridge rows go before each booking
 * row. Any booking that ever reached GHL and is not already closed there is
 * cancelled ON THE GHL SIDE via a queued job that carries the GHL id — the
 * local rows are gone by the time it runs, so SyncBookingToGhl cannot do it.
 * Callers wrap this in their own transaction with authorization done.
 */
class PurgeBookings
{
    /**
     * @param  Collection<int, Booking>  $bookings
     */
    public function handle(Salon $salon, Collection $bookings): void
    {
        foreach ($bookings as $booking) {
            // Mirror the deletion to GHL: anything synced and still "open"
            // over there becomes cancelled. Demo salons never sync (no job).
            if (! $salon->is_demo
                && $booking->ghl_appointment_id !== null
                && ! in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
                CancelGhlAppointmentRemotely::dispatch($salon->id, $booking->ghl_appointment_id)->afterCommit();
            }

            $booking->items()->delete();
            $booking->statusEvents()->delete();
            $booking->delete();
        }
    }

    /**
     * The bookings that make deletion dangerous: still open, starting in
     * the future — surfaced in every blast-radius confirm step.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<int, Booking>
     */
    public static function upcoming(Collection $bookings): Collection
    {
        return $bookings
            ->filter(fn (Booking $booking): bool => in_array($booking->status, [BookingStatus::Booked, BookingStatus::Confirmed], true)
                && $booking->items->contains(fn ($item): bool => $item->starts_at->isFuture()))
            ->values();
    }
}

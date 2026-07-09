<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\User;
use App\Services\Booking\BookingPolicy;
use App\Services\Booking\SlotEngine;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move a booking to a new start (same stylist, same services — the items
 * shift as one block, keeping their stored durations and buffers). The slot
 * engine re-validates the new time under a stylist row lock, ignoring the
 * booking's own current slot so moving within or adjacent to itself never
 * false-conflicts. The timeline records "Rescheduled from X to Y" with the
 * actor, and the existing GHL appointment is UPDATED via the queued push
 * (hash diff → PUT on the stored ghl_appointment_id, never a duplicate;
 * unconnected/unmapped salons skip gracefully as always).
 *
 * Reschedule is front-desk-level (owner / admin / front desk) — stylists do
 * not move bookings, mirroring status management.
 */
class RescheduleBooking
{
    public function __construct(
        private SlotEngine $engine,
        private BookingPolicy $policy,
    ) {}

    /**
     * @param  string  $start  'Y-m-d H:i' in the salon timezone
     */
    public function handle(User $actor, Salon $salon, Booking $booking, string $start): Booking
    {
        if ($booking->salon_id !== $salon->id) {
            throw new AuthorizationException('That booking is not in this salon.');
        }

        if (! $actor->can('manageBookings', $salon)) {
            throw new AuthorizationException('You may not reschedule bookings.');
        }

        if ($booking->status === BookingStatus::Cancelled) {
            throw ValidationException::withMessages([
                'start' => __('A cancelled booking cannot be rescheduled.'),
            ]);
        }

        $items = $booking->items()->orderBy('starts_at')->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'start' => __('The booking has no service items.'),
            ]);
        }

        $tz = $salon->timezone;
        $newStart = CarbonImmutable::parse($start, $tz);
        $this->policy->assertCreatable($salon, $newStart, false);

        $oldStart = $items->first()->starts_at;
        $delta = (int) round($oldStart->diffInSeconds($newStart, false));

        $booking = DB::transaction(function () use ($salon, $booking, $items, $actor, $delta, $oldStart, $newStart, $tz): Booking {
            // Lock the stylists involved, then re-validate every shifted item
            // against the engine — ignoring this booking's own current slot.
            foreach ($items->pluck('stylist_id')->unique()->sort() as $stylistId) {
                User::query()->whereKey($stylistId)->lockForUpdate()->first();
            }

            foreach ($items as $item) {
                $blocked = (int) round($item->starts_at->diffInMinutes($item->ends_at)) + (int) $item->buffer_min;

                if (! $this->engine->isAvailable($salon, (int) $item->stylist_id, $item->starts_at->addSeconds($delta), $blocked, $booking->id)) {
                    throw ValidationException::withMessages([
                        'start' => __('That time was just taken. Please choose another slot.'),
                    ]);
                }
            }

            foreach ($items as $item) {
                $item->update([
                    'starts_at' => $item->starts_at->addSeconds($delta),
                    'ends_at' => $item->ends_at->addSeconds($delta),
                ]);
            }

            $booking->statusEvents()->create([
                'salon_id' => $salon->id,
                'from_status' => $booking->status,
                'to_status' => $booking->status,
                'note' => __('Rescheduled from :from to :to', [
                    'from' => $oldStart->setTimezone($tz)->format('M j, g:i A'),
                    'to' => $newStart->setTimezone($tz)->format('M j, g:i A'),
                ]),
                'actor_user_id' => $actor->id,
            ]);

            // Bump updated_at so inbound last-change-wins sees this edit.
            $booking->touch();

            return $booking;
        });

        // Mirror to GHL after commit: the stored appointment id makes this an
        // UPDATE of the existing appointment, never a new one.
        SyncBookingToGhl::queueFor($booking);

        return $booking->fresh();
    }
}

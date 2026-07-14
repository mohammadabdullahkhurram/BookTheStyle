<?php

namespace App\Enums;

/**
 * Lifecycle of a client visit (SPEC §4), shaped to the salon workflow:
 * a booking is BOOKED (active — auto-confirmed in GHL on push), then either
 * CHECKED IN (case Arrived, GHL "showed"), NO-SHOW (manual or automatic once
 * the end time passes), or CANCELLED. Rescheduling is a time change, not a
 * status. There is no user-facing "confirm" step.
 *
 * Confirmed / InService / Completed remain as legacy cases so historical
 * bookings stay valid; they expose no forward buttons beyond the new model.
 */
enum BookingStatus: string
{
    case Booked = 'booked';
    case Confirmed = 'confirmed';
    case Arrived = 'arrived';
    case InService = 'in_service';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::Confirmed => 'Confirmed',
            self::Arrived => 'Checked in',
            self::InService => 'In service',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    /**
     * Imperative label for the BUTTON that transitions a booking INTO this
     * status. Status pills keep label(); action buttons say what they do.
     */
    public function actionLabel(): string
    {
        return match ($this) {
            self::Booked => 'Rebook',
            self::Confirmed => 'Confirm',
            self::Arrived => 'Check in',
            self::InService => 'Start service',
            self::Completed => 'Complete',
            self::Cancelled => 'Cancel booking',
            self::NoShow => 'Mark no-show',
        };
    }

    /** Toast copy after a successful transition into this status. */
    public function actionToast(): string
    {
        return match ($this) {
            self::Arrived => 'Checked in.',
            self::InService => 'Service started.',
            self::Completed => 'Booking completed.',
            self::Cancelled => 'Booking cancelled.',
            self::NoShow => 'Marked as no-show.',
            default => 'Booking updated.',
        };
    }

    /**
     * wire:confirm copy for transitions that are destructive and fire GHL /
     * reminder side-effects; null = commit without confirmation.
     */
    public function confirmMessage(): ?string
    {
        return match ($this) {
            self::Cancelled => "Cancel this booking? The client's appointment is removed and GoHighLevel is updated. This cannot be undone.",
            self::NoShow => 'Mark this booking as a no-show? This is recorded on the client and synced to GoHighLevel. This cannot be undone.',
            default => null,
        };
    }

    /**
     * Flux badge colour.
     */
    public function color(): string
    {
        return match ($this) {
            self::Booked => 'zinc',
            self::Confirmed => 'blue',
            self::Arrived => 'amber',
            self::InService => 'blue',
            self::Completed => 'green',
            self::Cancelled => 'zinc',
            self::NoShow => 'red',
        };
    }

    /**
     * Allowed forward transitions (server-enforced).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // The four salon actions: checked in / no show / cancel
            // (+ reschedule, which is a time change, not a status).
            self::Booked => [self::Arrived, self::NoShow, self::Cancelled],
            // Legacy pre-arrival state behaves like Booked.
            self::Confirmed => [self::Arrived, self::NoShow, self::Cancelled],
            self::Arrived => [self::Cancelled],
            // Legacy in-progress state can still be cancelled.
            self::InService => [self::Cancelled],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}

<?php

namespace App\Services\Booking;

/**
 * The resolved timing for a (service, stylist) pair.
 *
 * - serviceMinutes      — the stylist's time for the service (what the client gets).
 * - bufferMinutes       — cleanup/turnaround after the appointment.
 * - blockedMinutes()    — service + buffer; what the stylist's calendar/availability
 *                         is occupied for (the engine works in these).
 * - clientFacingMinutes() — what the customer sees in the UI (excludes the buffer).
 */
final class ResolvedDuration
{
    public function __construct(
        public readonly int $serviceMinutes,
        public readonly int $bufferMinutes,
    ) {}

    public function blockedMinutes(): int
    {
        return $this->serviceMinutes + $this->bufferMinutes;
    }

    public function clientFacingMinutes(): int
    {
        return $this->serviceMinutes;
    }
}

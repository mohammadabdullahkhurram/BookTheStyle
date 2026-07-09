<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\BookingGhlAppointment;
use App\Services\Ghl\GhlBookingPusher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued GHL push for one booking (create / reschedule / cancel all dispatch
 * this same job — it pushes the booking's CURRENT state, so it is naturally
 * idempotent and re-dispatchable). The booking itself is the source of truth
 * and has already committed; this never blocks or fails it.
 *
 * Runs on the database queue (SPEC §8): locally via `composer dev` (which
 * starts queue:listen) or `php artisan queue:work`; in production via the
 * scheduler's every-minute queue:work --stop-when-empty (see
 * routes/console.php — Hostinger has no always-on worker; Phase 7 wires the
 * one crontab line for schedule:run).
 *
 * Retries with backoff on top of the client's own 429/5xx retries; when every
 * attempt fails, failed() records a visible sync error on the booking rather
 * than losing the failure silently.
 */
class SyncBookingToGhl implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public int $bookingId) {}

    /**
     * @return list<int> seconds before each retry
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(GhlBookingPusher $pusher): void
    {
        // SalonScope is a no-op off-request; load by key and let the pusher
        // work through the booking's own salon (tenant-safe by construction).
        $booking = Booking::query()->find($this->bookingId);

        if ($booking === null) {
            return;
        }

        $pusher->push($booking);
    }

    public function failed(?Throwable $exception): void
    {
        // Flag every stylist slice that never reached "synced" — the pusher
        // already recorded per-slice errors; this covers pre-slice failures.
        BookingGhlAppointment::query()
            ->where('booking_id', $this->bookingId)
            ->where(fn ($query) => $query->whereNull('sync_status')->orWhere('sync_status', '!=', GhlBookingPusher::STATUS_SYNCED))
            ->update([
                'sync_status' => GhlBookingPusher::STATUS_FAILED,
                // GhlApiException messages are user-safe and token-free.
                'sync_error' => mb_substr($exception?->getMessage() ?? __('Unknown error.'), 0, 500),
            ]);
    }
}

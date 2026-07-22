<?php

namespace App\Jobs;

use App\Models\Booking;
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
     * The one way to request a push: flags the booking PENDING (visible sync
     * state instead of a silent queue entry) and dispatches after commit.
     * Bookkeeping writes go through the base query so updated_at — which the
     * inbound conflict resolution compares against — never moves for them.
     */
    public static function queueFor(Booking $booking): void
    {
        // Demo salons are inert: nothing ever reaches GHL.
        if ($booking->salon->is_demo) {
            return;
        }

        Booking::query()->whereKey($booking->id)->toBase()->update([
            'ghl_sync_status' => GhlBookingPusher::STATUS_PENDING,
        ]);

        self::dispatch($booking->id)->afterCommit();
    }

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

        // Every attempt is stamped (success or not) so a failed booking can
        // say when the push last ran — again without touching updated_at.
        Booking::query()->whereKey($booking->id)->toBase()->update([
            'ghl_last_attempt_at' => now(),
        ]);

        $pusher->push($booking);
    }

    public function failed(?Throwable $exception): void
    {
        // toBase: a failure record must not bump updated_at, or the retry
        // window would make the app look "newer" to inbound conflict checks.
        Booking::query()->whereKey($this->bookingId)->toBase()->update([
            'ghl_sync_status' => GhlBookingPusher::STATUS_FAILED,
            // GhlApiException messages are user-safe and token-free.
            'ghl_sync_error' => mb_substr($exception?->getMessage() ?? __('Unknown error.'), 0, 500),
        ]);
    }
}

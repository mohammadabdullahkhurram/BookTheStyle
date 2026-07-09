<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Close out elapsed bookings, two passes over anything whose every item
 * ended in the past (UTC comparison — timezone/DST-safe for every salon):
 *
 * 1. Still in the active pre-arrival state (booked, or legacy confirmed) →
 *    NO-SHOW, mirrored to GHL as noshow via the queued push.
 * 2. CHECKED IN (arrived) → COMPLETED. The visit happened and is finished.
 *    Completed maps to the same GHL status the check-in already pushed
 *    ("showed"), so this pass never dispatches an outbound push — nothing
 *    changes in GHL and no echo loop can start. Only this job promotes
 *    checked-in to completed; the webhook never does.
 *
 * A rescheduled booking has a future end time and never qualifies for
 * either pass; cancelled / no-show / completed are excluded by status.
 * Idempotent: flipped bookings never match again. Counts are logged.
 *
 * Scheduled every five minutes (routes/console.php). Locally run
 * `php artisan schedule:work` (or invoke this command directly); in
 * production the Phase-7 crontab line `* * * * * php artisan schedule:run`
 * drives it.
 */
class CloseElapsedBookings extends Command
{
    protected $signature = 'bookings:close-elapsed';

    protected $description = 'No-show elapsed still-booked bookings and complete elapsed checked-in ones';

    public function handle(): int
    {
        $noShows = $this->close(
            [BookingStatus::Booked, BookingStatus::Confirmed],
            BookingStatus::NoShow,
            __('Automatically marked as no-show after the appointment time passed.'),
            pushToGhl: true, // booked → noshow IS a GHL status change
        );

        $completed = $this->close(
            [BookingStatus::Arrived],
            BookingStatus::Completed,
            __('Automatically completed after the appointment time passed.'),
            pushToGhl: false, // arrived and completed are both GHL "showed" — no push, no loop
        );

        if ($noShows > 0 || $completed > 0) {
            Log::info('Closed elapsed bookings', ['no_shows' => $noShows, 'completed' => $completed]);
        }

        $this->info("Marked {$noShows} booking(s) as no-show.");
        $this->info("Completed {$completed} checked-in booking(s).");

        return self::SUCCESS;
    }

    /**
     * @param  list<BookingStatus>  $from
     */
    private function close(array $from, BookingStatus $to, string $note, bool $pushToGhl): int
    {
        $flipped = 0;

        Booking::query()
            ->whereIn('status', array_map(fn (BookingStatus $status) => $status->value, $from))
            ->whereHas('items')
            ->whereDoesntHave('items', fn ($q) => $q->where('ends_at', '>', now()->utc()))
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use (&$flipped, $to, $note, $pushToGhl): void {
                foreach ($bookings as $booking) {
                    $fromStatus = $booking->status;
                    $booking->update(['status' => $to]);
                    $booking->statusEvents()->create([
                        'salon_id' => $booking->salon_id,
                        'from_status' => $fromStatus,
                        'to_status' => $to,
                        'note' => $note,
                        'actor_user_id' => null, // system
                    ]);

                    if ($pushToGhl) {
                        SyncBookingToGhl::dispatch($booking->id)->afterCommit();
                    }

                    $flipped++;
                }
            });

        return $flipped;
    }
}

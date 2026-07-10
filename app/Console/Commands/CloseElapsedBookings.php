<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use App\Models\Salon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Close out elapsed bookings per each salon's booking-automation settings
 * (salons.auto_no_show / auto_no_show_grace_minutes / auto_complete). Two
 * passes over anything whose every item ended before the pass's cutoff (UTC
 * instant comparison — timezone/DST-safe for every salon, and the grace
 * period is pure instant arithmetic on top):
 *
 * 1. AUTO-NO-SHOW (opt-in, default OFF): salons with auto_no_show enabled
 *    only. Still in the active pre-arrival state (booked, or legacy
 *    confirmed) AND past end time + the salon's grace period → NO-SHOW,
 *    mirrored to GHL as noshow via the queued push. Salons with the toggle
 *    off are skipped entirely — staff mark no-shows manually there (the
 *    manual action is always available regardless).
 * 2. AUTO-COMPLETE (default ON): salons with auto_complete enabled only.
 *    CHECKED IN (arrived) past end time → COMPLETED. Completed maps to the
 *    same GHL status the check-in already pushed ("showed"), so this pass
 *    never dispatches an outbound push — nothing changes in GHL and no echo
 *    loop can start. Only this job promotes checked-in to completed; the
 *    webhook never does.
 *
 * A rescheduled booking has a future end time and never qualifies for
 * either pass; cancelled / no-show / completed are excluded by status.
 * Idempotent: flipped bookings never match again. Counts are logged.
 * Bookings are scoped per salon explicitly (the tenancy global scope is a
 * no-op in console).
 *
 * Scheduled every five minutes (routes/console.php). Locally run
 * `php artisan schedule:work` (or invoke this command directly); in
 * production the Phase-7 crontab line `* * * * * php artisan schedule:run`
 * drives it.
 */
class CloseElapsedBookings extends Command
{
    protected $signature = 'bookings:close-elapsed';

    protected $description = 'No-show elapsed still-booked bookings and complete elapsed checked-in ones, per salon automation settings';

    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        $noShows = 0;
        $completed = 0;

        Salon::query()
            ->where(fn ($q) => $q->where('auto_no_show', true)->orWhere('auto_complete', true))
            ->orderBy('id')
            ->each(function (Salon $salon) use ($now, &$noShows, &$completed): void {
                if ($salon->auto_no_show) {
                    $noShows += $this->close(
                        $salon,
                        [BookingStatus::Booked, BookingStatus::Confirmed],
                        BookingStatus::NoShow,
                        $now->subMinutes($salon->auto_no_show_grace_minutes),
                        __('Automatically marked as no-show after the appointment time passed.'),
                        pushToGhl: true, // booked → noshow IS a GHL status change
                    );
                }

                if ($salon->auto_complete) {
                    $completed += $this->close(
                        $salon,
                        [BookingStatus::Arrived],
                        BookingStatus::Completed,
                        $now,
                        __('Automatically completed after the appointment time passed.'),
                        pushToGhl: false, // arrived and completed are both GHL "showed" — no push, no loop
                    );
                }
            });

        if ($noShows > 0 || $completed > 0) {
            Log::info('Closed elapsed bookings', ['no_shows' => $noShows, 'completed' => $completed]);
        }

        $this->info("Marked {$noShows} booking(s) as no-show.");
        $this->info("Completed {$completed} checked-in booking(s).");

        return self::SUCCESS;
    }

    /**
     * Flip the salon's bookings in $from whose every item ended before
     * $cutoff, recording a system status event.
     *
     * @param  list<BookingStatus>  $from
     */
    private function close(Salon $salon, array $from, BookingStatus $to, CarbonImmutable $cutoff, string $note, bool $pushToGhl): int
    {
        $flipped = 0;

        Booking::query()
            ->where('salon_id', $salon->id)
            ->whereIn('status', array_map(fn (BookingStatus $status) => $status->value, $from))
            ->whereHas('items')
            ->whereDoesntHave('items', fn ($q) => $q->where('ends_at', '>', $cutoff))
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
                        SyncBookingToGhl::queueFor($booking);
                    }

                    $flipped++;
                }
            });

        return $flipped;
    }
}

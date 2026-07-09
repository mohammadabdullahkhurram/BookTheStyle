<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Flip genuinely-elapsed, still-active bookings to no-show and mirror the
 * change to GHL. A booking qualifies only when its status is the active
 * pre-arrival state (booked, or the legacy confirmed) AND every one of its
 * items ended in the past — a rescheduled booking has a future end and never
 * qualifies; checked-in, cancelled and existing no-shows are excluded by
 * status. Idempotent: once flipped, a booking never matches again. Item
 * times are stored in UTC, so the comparison is timezone/DST-safe for every
 * salon.
 *
 * Scheduled every five minutes (routes/console.php). Locally, run the
 * scheduler with `php artisan schedule:work` (or invoke this command
 * directly); in production the Phase-7 crontab line
 * `* * * * * php artisan schedule:run` drives it.
 */
class AutoNoShowBookings extends Command
{
    protected $signature = 'bookings:auto-no-show';

    protected $description = 'Mark elapsed, still-booked bookings as no-show and sync GHL';

    public function handle(): int
    {
        $flipped = 0;

        Booking::query()
            ->whereIn('status', [BookingStatus::Booked->value, BookingStatus::Confirmed->value])
            ->whereHas('items')
            ->whereDoesntHave('items', fn ($q) => $q->where('ends_at', '>', now()->utc()))
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use (&$flipped): void {
                foreach ($bookings as $booking) {
                    $from = $booking->status;
                    $booking->update(['status' => BookingStatus::NoShow]);
                    $booking->statusEvents()->create([
                        'salon_id' => $booking->salon_id,
                        'from_status' => $from,
                        'to_status' => BookingStatus::NoShow,
                        'note' => __('Automatically marked as no-show after the appointment time passed.'),
                        'actor_user_id' => null, // system
                    ]);

                    SyncBookingToGhl::dispatch($booking->id)->afterCommit();
                    $flipped++;
                }
            });

        if ($flipped > 0) {
            Log::info('Auto no-show flipped bookings', ['count' => $flipped]);
        }

        $this->info("Marked {$flipped} booking(s) as no-show.");

        return self::SUCCESS;
    }
}

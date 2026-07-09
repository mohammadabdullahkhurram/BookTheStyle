<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\Ghl\GhlStatusMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Realign each mirrored booking's local sync tracking with its ACTUAL
 * current status — tracking == reality — breaking any echo/ping-pong loop
 * left by past sync bugs. Local only: GHL is never called; the payload hash
 * is cleared so the next outbound push re-asserts the true state with one
 * idempotent update. Only rows whose tracking disagrees are touched, so
 * re-running is a no-op. Optionally scoped to one salon id.
 */
class RepairGhlSyncState extends Command
{
    protected $signature = 'ghl:repair-sync-state {salon? : Limit to one salon id}';

    protected $description = 'Reset per-booking GHL sync tracking to match the booking\'s actual status';

    public function handle(): int
    {
        $repaired = 0;

        Booking::query()
            ->whereNotNull('ghl_appointment_id')
            ->when($this->argument('salon') !== null, fn ($q) => $q->where('salon_id', (int) $this->argument('salon')))
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use (&$repaired): void {
                foreach ($bookings as $booking) {
                    $actual = GhlStatusMap::toGhl($booking->status);

                    if ($booking->ghl_last_pushed_status === $actual && $booking->ghl_sync_status !== 'failed') {
                        continue; // tracking already matches reality
                    }

                    $booking->forceFill([
                        'ghl_last_pushed_status' => $actual,
                        'ghl_payload_hash' => null, // next push re-asserts the true state
                        'ghl_sync_status' => 'synced',
                        'ghl_sync_error' => null,
                    ])->save();

                    $repaired++;
                }
            });

        if ($repaired > 0) {
            Log::info('Repaired GHL sync tracking', ['count' => $repaired]);
        }

        $this->info("Repaired {$repaired} booking(s).");

        return self::SUCCESS;
    }
}

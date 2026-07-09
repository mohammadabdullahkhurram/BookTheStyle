<?php

namespace App\Jobs;

use App\Models\Salon;
use App\Services\Ghl\GhlAvailabilityPusher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued push of the master calendar's slot settings (Phase 6e): slot
 * duration = the longest active service, slot interval = the app's
 * 15-minute grid, post-appointment buffer = the longest cleanup buffer when
 * the buffers flag is on. Dispatched when services / overrides / flags
 * change and by the manual availability sync; a clean idempotent overwrite
 * every time.
 */
class SyncGhlCalendarSlotSettings implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public int $salonId) {}

    /**
     * @return list<int> seconds before each retry
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /** Quiet no-op for unconnected salons. */
    public static function queueFor(Salon $salon): void
    {
        if (! ($salon->ghlConnection()->first()?->isConnected() ?? false)) {
            return;
        }

        self::dispatch($salon->id)->afterCommit();
    }

    public function handle(GhlAvailabilityPusher $pusher): void
    {
        $salon = Salon::query()->find($this->salonId);

        if ($salon === null) {
            return;
        }

        $pusher->pushCalendarSlotSettings($salon);
    }

    public function failed(?Throwable $exception): void
    {
        // No per-row home for this one — the log is the audit trail.
        Log::warning('GHL calendar slot settings sync failed', [
            'salon_id' => $this->salonId,
            'error' => $exception?->getMessage(),
        ]);
    }
}

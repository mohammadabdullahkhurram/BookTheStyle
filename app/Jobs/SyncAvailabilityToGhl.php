<?php

namespace App\Jobs;

use App\Models\Salon;
use App\Models\StylistProfile;
use App\Services\Ghl\GhlAvailabilityPusher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued availability mirror for one stylist (Phase 6e): pushes the
 * stylist's CURRENT weekly hours + time off to their GHL user availability
 * schedule on the master calendar. State-driven and re-dispatchable — every
 * availability edit queues this same job, and the pusher's hash makes an
 * unchanged re-sync a no-op.
 *
 * Runs on the database queue like the booking sync (drained every minute by
 * the scheduler in production; live under `composer dev`). Retries with
 * backoff on top of the client's own 429/5xx retries; when every attempt
 * fails, failed() records a visible availability-sync error on the stylist
 * profile (Settings → Integrations) rather than dying silently.
 */
class SyncAvailabilityToGhl implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public int $stylistProfileId) {}

    /**
     * @return list<int> seconds before each retry
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Request a push for one profile: flags it PENDING (visible sync state)
     * and dispatches after commit. Bookkeeping goes through the base query —
     * no timestamp churn.
     */
    public static function queueFor(StylistProfile $profile): void
    {
        StylistProfile::query()->whereKey($profile->id)->toBase()->update([
            'ghl_availability_status' => GhlAvailabilityPusher::STATUS_PENDING,
        ]);

        self::dispatch($profile->id)->afterCommit();
    }

    /**
     * The availability-edit hook: queue a push for one stylist of a salon,
     * quietly doing nothing when the salon isn't connected or the stylist
     * isn't mapped to a GHL provider.
     */
    public static function queueForStylist(Salon $salon, int $stylistUserId): void
    {
        if (! ($salon->ghlConnection()->first()?->isConnected() ?? false)) {
            return;
        }

        $profile = StylistProfile::forSalon($salon)
            ->where('user_id', $stylistUserId)
            ->whereNotNull('ghl_user_id')
            ->first();

        if ($profile !== null) {
            self::queueFor($profile);
        }
    }

    /**
     * Queue a push for EVERY mapped stylist of the salon (manual sync-all,
     * remapping, timezone change). No-op when unconnected.
     */
    public static function queueForSalon(Salon $salon): int
    {
        if (! ($salon->ghlConnection()->first()?->isConnected() ?? false)) {
            return 0;
        }

        $profiles = StylistProfile::forSalon($salon)->whereNotNull('ghl_user_id')->get();

        foreach ($profiles as $profile) {
            self::queueFor($profile);
        }

        return $profiles->count();
    }

    public function handle(GhlAvailabilityPusher $pusher): void
    {
        $profile = StylistProfile::query()->find($this->stylistProfileId);

        if ($profile === null) {
            return;
        }

        $pusher->push($profile);
    }

    public function failed(?Throwable $exception): void
    {
        StylistProfile::query()->whereKey($this->stylistProfileId)->toBase()->update([
            'ghl_availability_status' => GhlAvailabilityPusher::STATUS_FAILED,
            // GhlApiException messages are user-safe and token-free.
            'ghl_availability_error' => mb_substr($exception?->getMessage() ?? __('Unknown error.'), 0, 500),
        ]);
    }
}

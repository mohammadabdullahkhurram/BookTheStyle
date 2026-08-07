<?php

namespace App\Services\Health\Checks;

use App\Services\Ghl\GhlApiException;
use App\Services\Ghl\GhlClient;
use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

/**
 * The GHL SIDE of the connection, as far as BTS can observe it: a real
 * read-only API call (list the location's calendars) with the salon's
 * stored token. This is different from GhlConnectionConfigured (which only
 * proves the credentials are SAVED) — a revoked or rotated token passes
 * that one and fails this one. Read-only: one GET, nothing stamped,
 * nothing written to GHL.
 */
class GhlConnectionLive implements HealthCheck
{
    public function key(): string
    {
        return 'ghl-live';
    }

    public function label(): string
    {
        return __('GoHighLevel link (live)');
    }

    public function run(HealthContext $context): CheckResult
    {
        $connection = $context->salon->ghlConnection()->first();

        if ($connection === null || blank($connection->location_id) || ! $connection->hasToken()) {
            return CheckResult::warn(
                __('Not connected to GHL, so there is no live link to verify. Bookings work in BookTheStyle; nothing syncs until GHL is connected.'),
                __('Connect it on Settings → Integrations (SOP part C).'),
            );
        }

        try {
            $calendars = GhlClient::fromConnection($connection)->calendars();
        } catch (GhlApiException $e) {
            return CheckResult::fail(
                __('GHL refused the salon\'s stored credentials — the link is DOWN even though it is configured: :reason. Bookings are not syncing, reminders and the voice AI\'s calendar view are stale.', ['reason' => $e->getMessage()]),
                __('The token was likely revoked or rotated inside GHL. Re-paste the Private Integration Token on Settings → Integrations, then verify there.'),
            );
        } catch (\Throwable) {
            return CheckResult::fail(
                __('GHL did not answer the live test call — their API may be down or unreachable from this server. The stored credentials could not be verified either way.'),
                __('Try again in a few minutes; if it persists, check GHL\'s status page.'),
            );
        }

        return CheckResult::pass(trans_choice(
            'GHL answered with the salon\'s own token — the link is alive (:count calendar visible).|GHL answered with the salon\'s own token — the link is alive (:count calendars visible).',
            count($calendars),
            ['count' => count($calendars)],
        ));
    }
}

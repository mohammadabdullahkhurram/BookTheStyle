<?php

namespace App\Services\Health\Checks;

use App\Services\BookingApi\ApiError;
use App\Services\BookingApi\VoiceBookingApi;
use App\Services\Diagnostics\ConnectionDiagnostics;
use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use App\Services\Health\NeedsTestRecords;
use Carbon\CarbonImmutable;

/**
 * THE one sanctioned mutation in the health check: a real booking through
 * the same engine path the Voice AI uses — on the disposable is_test
 * records only, removed at clean-up, invisible to clients throughout.
 *
 * The appointment ALWAYS lands at ConnectionDiagnostics::TEST_BOOKING_TIME
 * (2:00 PM, salon timezone) so the salon can keep that time clear during
 * real testing. Date selection: the first day whose 2:00 PM is still ahead;
 * a 2:00 PM already held by a previous test run is REUSED (the engine's
 * idempotent create returns the same confirmation), and any other refusal
 * rolls to the next day's 2:00 PM — the check never books a different time.
 * Only this check changed for the fixed time — the real booking engine,
 * availability, and slot logic are untouched and shared with production.
 */
class TestBookingSucceeds implements HealthCheck, NeedsTestRecords
{
    /** Days of 2:00 PMs to try before giving up (full-availability stylist). */
    private const MAX_DAYS = 7;

    public function __construct(private VoiceBookingApi $api) {}

    public function key(): string
    {
        return 'test-booking';
    }

    public function label(): string
    {
        return __('Test booking');
    }

    public function run(HealthContext $context): CheckResult
    {
        if ($context->testClient === null) {
            return CheckResult::warn(__('Skipped — no test records in this run (the scheduled monitor never books).'));
        }

        $now = CarbonImmutable::now($context->salon->timezone);
        $lastReason = null;

        foreach (range(0, self::MAX_DAYS - 1) as $offset) {
            $twoPm = $now->startOfDay()->addDays($offset)
                ->setTimeFromTimeString(ConnectionDiagnostics::TEST_BOOKING_TIME);

            if ($twoPm->lte($now)) {
                continue; // today's 2:00 PM is already gone — roll forward
            }

            try {
                $created = $this->api->create($context->salon, [
                    'service' => ConnectionDiagnostics::SERVICE_NAME,
                    'stylist' => ConnectionDiagnostics::STYLIST_NAME,
                    'date' => $twoPm->format('Y-m-d'),
                    'time' => ConnectionDiagnostics::TEST_BOOKING_TIME,
                    'client' => ['name' => ConnectionDiagnostics::CLIENT_NAME, 'phone' => $context->testClient->phone, 'email' => $context->testClient->email],
                    'notes' => 'Health check — safe to ignore; cleaned up automatically.',
                ]);
            } catch (ApiError $e) {
                // That day's 2:00 PM is not bookable (held, or inside the
                // salon's notice window) — try the NEXT day's 2:00 PM.
                $lastReason = $e->toResponse()['message'] ?? $e->errorCode;

                continue;
            }

            if (($created['success'] ?? false) === true) {
                return CheckResult::pass(__(':client booked :service with :stylist for :time — the booking engine works end to end. The test appointment is always at 2:00 PM (keep that time clear while testing) and is removed at clean-up.', [
                    'client' => ConnectionDiagnostics::CLIENT_NAME,
                    'service' => ConnectionDiagnostics::SERVICE_NAME,
                    'stylist' => ConnectionDiagnostics::STYLIST_NAME,
                    'time' => $created['confirmation']['spoken_time'] ?? $twoPm->format('l, F j \a\t g:i A'),
                ]));
            }

            $lastReason = $created['message'] ?? __('unknown');
        }

        return CheckResult::fail(
            __('No 2:00 PM could be booked in the next :days days: :reason', ['days' => self::MAX_DAYS, 'reason' => $lastReason ?? __('unknown')]),
            __('Fix the Availability line above (booking policy limits are the usual cause), then run again.'),
        );
    }
}

<?php

namespace App\Services\Health\Checks;

use App\Services\Diagnostics\ConnectionDiagnostics;
use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use App\Services\Health\NeedsTestRecords;

/**
 * THE one sanctioned mutation in the health check: the pinned test
 * appointment — ConnectionDiagnostics::TEST_BOOKING_DATE at
 * TEST_BOOKING_TIME (28 June 3004, 2:00 PM salon time), on the disposable
 * is_test records only, invisible to clients, removed at clean-up.
 *
 * Far-future by design: real booking policy caps advance booking at a
 * year, so this slot can NEVER collide with a real appointment — and for
 * the same reason the appointment is laid by the diagnostics layer after
 * validation against the live SlotEngine, not through the policy-gated
 * create path (which rightly refuses year 3004). Re-runs REUSE the same
 * appointment: same date, same time, never a duplicate, never a shift.
 * Real booking, availability, and date handling are untouched.
 */
class TestBookingSucceeds implements HealthCheck, NeedsTestRecords
{
    public function __construct(private ConnectionDiagnostics $diagnostics) {}

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
        if ($context->testStylist === null || $context->testService === null || $context->testClient === null) {
            return CheckResult::warn(__('Skipped — no test records in this run (the scheduled monitor never books).'));
        }

        try {
            $result = $this->diagnostics->ensureTestAppointment(
                $context->salon,
                $context->testStylist,
                $context->testService,
                $context->testClient,
            );
        } catch (\RuntimeException $e) {
            return CheckResult::fail(
                $e->getMessage(),
                __('Run the check again — the test records (including the stylist\'s full availability) are recreated on every run.'),
            );
        }

        $spoken = ConnectionDiagnostics::testBookingInstant($context->salon)->format('l, F j, Y \a\t g:i A');

        return CheckResult::pass($result['reused']
            ? __('The pinned test appointment for :time is already in place (reused — never duplicated). The engine validated the slot; the appointment is removed at clean-up.', ['time' => $spoken])
            : __(':client booked :service with :stylist for :time — the slot was validated by the live availability engine and the appointment stored end to end. Pinned far-future on purpose: nobody real can ever book it. Removed at clean-up.', [
                'client' => ConnectionDiagnostics::CLIENT_NAME,
                'service' => ConnectionDiagnostics::SERVICE_NAME,
                'stylist' => ConnectionDiagnostics::STYLIST_NAME,
                'time' => $spoken,
            ]));
    }
}

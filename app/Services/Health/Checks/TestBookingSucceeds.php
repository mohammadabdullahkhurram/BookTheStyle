<?php

namespace App\Services\Health\Checks;

use App\Services\BookingApi\ApiError;
use App\Services\BookingApi\VoiceBookingApi;
use App\Services\Diagnostics\ConnectionDiagnostics;
use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use App\Services\Health\NeedsTestRecords;

/**
 * THE one sanctioned mutation in the health check: a real booking through
 * the same engine path the Voice AI uses — on the disposable is_test
 * records only, invisible to clients, removed at clean-up.
 *
 * The appointment is PINNED to ConnectionDiagnostics::TEST_BOOKING_DATE at
 * TEST_BOOKING_TIME (28 June 3004, 2:00 PM salon time). Real booking
 * policy caps advance booking at a year — which is exactly why the slot
 * can never collide with anything real — and designated is_test clients
 * are exempt from that window (BookingPolicy), so this books through the
 * FULL policy-gated engine path end to end. Re-runs REUSE the same
 * appointment via the engine's idempotent create: same date, same time,
 * never a duplicate, never a shift.
 */
class TestBookingSucceeds implements HealthCheck, NeedsTestRecords
{
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

        $instant = ConnectionDiagnostics::testBookingInstant($context->salon);

        try {
            $created = $this->api->create($context->salon, [
                'service' => ConnectionDiagnostics::SERVICE_NAME,
                'stylist' => ConnectionDiagnostics::STYLIST_NAME,
                'date' => $instant->format('Y-m-d'),
                'time' => ConnectionDiagnostics::TEST_BOOKING_TIME,
                'client' => ['name' => ConnectionDiagnostics::CLIENT_NAME, 'phone' => $context->testClient->phone, 'email' => $context->testClient->email],
                'notes' => 'Health check — safe to ignore; cleaned up automatically.',
            ]);
        } catch (ApiError $e) {
            return CheckResult::fail(
                __('The engine refused the pinned test booking (:date at :time): :reason', [
                    'date' => ConnectionDiagnostics::TEST_BOOKING_DATE,
                    'time' => ConnectionDiagnostics::TEST_BOOKING_TIME,
                    'reason' => $e->toResponse()['message'] ?? $e->errorCode,
                ]),
                __('Fix the Availability line above, then run again — the test records are recreated on every run.'),
            );
        }

        if (($created['success'] ?? false) !== true) {
            return CheckResult::fail(__('The engine did not confirm the booking: :reason', ['reason' => $created['message'] ?? __('unknown')]));
        }

        $spoken = $instant->format('l, F j, Y \a\t g:i A');

        return CheckResult::pass(($created['idempotent'] ?? false)
            ? __('The pinned test appointment for :time is already in place (reused — never duplicated). The booking engine works end to end; the appointment is removed at clean-up.', ['time' => $spoken])
            : __(':client booked :service with :stylist for :time through the full engine path — the booking engine works end to end. Pinned far-future on purpose: nobody real can ever book that slot. Removed at clean-up.', [
                'client' => ConnectionDiagnostics::CLIENT_NAME,
                'service' => ConnectionDiagnostics::SERVICE_NAME,
                'stylist' => ConnectionDiagnostics::STYLIST_NAME,
                'time' => $spoken,
            ]));
    }
}

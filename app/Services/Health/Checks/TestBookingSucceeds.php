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
 * records only, removed at clean-up, invisible to clients throughout.
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

        if ($context->slot === null) {
            return CheckResult::fail(
                __('Skipped — no open time was available to book.'),
                __('Fix the Availability line first, then run again.'),
            );
        }

        try {
            $created = $this->api->create($context->salon, [
                'service' => ConnectionDiagnostics::SERVICE_NAME,
                'stylist' => ConnectionDiagnostics::STYLIST_NAME,
                'date' => $context->slot['date'],
                'time' => $context->slot['time'],
                'client' => ['name' => ConnectionDiagnostics::CLIENT_NAME, 'phone' => $context->testClient->phone, 'email' => $context->testClient->email],
                'notes' => 'Health check — safe to ignore; cleaned up automatically.',
            ]);

            if (($created['success'] ?? false) === true) {
                return CheckResult::pass(__(':client booked :service with :stylist for :time — the booking engine works end to end. The test appointment is removed at clean-up.', [
                    'client' => ConnectionDiagnostics::CLIENT_NAME,
                    'service' => ConnectionDiagnostics::SERVICE_NAME,
                    'stylist' => ConnectionDiagnostics::STYLIST_NAME,
                    'time' => $created['confirmation']['spoken_time'] ?? $context->slot['spoken'],
                ]));
            }

            return CheckResult::fail(__('The engine did not confirm the booking: :reason', ['reason' => $created['message'] ?? __('unknown')]));
        } catch (ApiError $e) {
            return CheckResult::fail(__('The booking was refused: :reason', ['reason' => $e->toResponse()['message'] ?? $e->errorCode]));
        }
    }
}

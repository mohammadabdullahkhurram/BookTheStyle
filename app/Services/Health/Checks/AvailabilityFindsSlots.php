<?php

namespace App\Services\Health\Checks;

use App\Services\BookingApi\ApiError;
use App\Services\BookingApi\VoiceBookingApi;
use App\Services\Diagnostics\ConnectionDiagnostics;
use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use App\Services\Health\NeedsTestRecords;

class AvailabilityFindsSlots implements HealthCheck, NeedsTestRecords
{
    public function __construct(private VoiceBookingApi $api) {}

    public function key(): string
    {
        return 'availability';
    }

    public function label(): string
    {
        return __('Availability');
    }

    public function run(HealthContext $context): CheckResult
    {
        try {
            $availability = $this->api->availability($context->salon, ['service' => ConnectionDiagnostics::SERVICE_NAME]);
            $slot = $availability['slots'][0] ?? null;

            if ($slot === null) {
                return CheckResult::fail(
                    __('No open times came back for the test stylist even with full hours.'),
                    __('Check the salon\'s booking policy (advance-notice and booking-window limits) and run the check again.'),
                );
            }

            $context->slot = ['date' => $slot['date'], 'time' => $slot['time'], 'spoken' => $slot['spoken']];

            return CheckResult::pass(__('The engine found :count open times for :service with :stylist — availability works.', [
                'count' => count($availability['slots']),
                'service' => ConnectionDiagnostics::SERVICE_NAME,
                'stylist' => ConnectionDiagnostics::STYLIST_NAME,
            ]));
        } catch (ApiError $e) {
            return CheckResult::fail(
                __('The availability engine refused the test request: :reason', ['reason' => $e->toResponse()['message'] ?? $e->errorCode]),
                __('Run the check again after fixing the reason above.'),
            );
        }
    }
}

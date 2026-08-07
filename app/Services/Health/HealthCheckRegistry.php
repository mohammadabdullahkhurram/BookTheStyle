<?php

namespace App\Services\Health;

use App\Models\Salon;
use App\Services\Diagnostics\ConnectionDiagnostics;

/**
 * The extensible health-check registry: every check class, grouped into
 * categories, run in order against one salon. Adding a check = one class
 * implementing HealthCheck + one entry here. The registry ensures the
 * disposable test records exist (ConnectionDiagnostics), builds the shared
 * context, runs each check exception-safely (a crashing check reports as a
 * FAIL line, never a broken page), and returns the grouped report plus the
 * pass/warn/fail summary.
 */
class HealthCheckRegistry
{
    /** @var array<string, string> category key → human label */
    public const CATEGORIES = [
        'integrations' => 'Integrations & Voice AI booking',
        'notifications' => 'Notifications',
        'schedule' => 'Scheduled jobs & queue',
        'readiness' => 'Salon readiness',
        'system' => 'System',
    ];

    /** @var array<string, list<class-string<HealthCheck>>> category key → checks */
    private const CHECKS = [
        'integrations' => [
            Checks\ApiTokenIssued::class,
            Checks\BookingEndpointReachable::class,
            Checks\AvailabilityFindsSlots::class,
            Checks\TestBookingSucceeds::class,
            Checks\WebhookSecretConfigured::class,
            Checks\GhlConnectionConfigured::class,
            Checks\WidgetReachable::class,
        ],
        'notifications' => [
            Checks\MailTransportConfigured::class,
            Checks\ClientMessagingPathway::class,
        ],
        'schedule' => [
            Checks\SchedulerHeartbeat::class,
            Checks\ScheduledTasksFresh::class,
            Checks\QueueHealth::class,
        ],
        'readiness' => [
            Checks\SalonHasServices::class,
            Checks\SalonHasBookableStaff::class,
            Checks\SalonHasHours::class,
            Checks\SalonBrandingSet::class,
        ],
        'system' => [
            Checks\DatabaseConnectivity::class,
            Checks\MigrationsUpToDate::class,
            Checks\StorageWritable::class,
            Checks\CachesBuilt::class,
            Checks\AppUrlSanity::class,
        ],
    ];

    public function __construct(private ConnectionDiagnostics $diagnostics) {}

    /**
     * @return array{
     *     categories: list<array{key: string, label: string, checks: list<array{key: string, label: string, status: string, message: string, fix: string|null}>}>,
     *     summary: array{pass: int, warn: int, fail: int}
     * }
     */
    public function run(Salon $salon): array
    {
        $records = $this->diagnostics->ensureTestRecords($salon);
        $context = new HealthContext($salon, $records['stylist'], $records['service'], $records['client']);

        $categories = [];
        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0];

        foreach (self::CATEGORIES as $key => $label) {
            $lines = [];

            foreach (self::CHECKS[$key] as $class) {
                /** @var HealthCheck $check */
                $check = app($class);

                try {
                    $result = $check->run($context);
                } catch (\Throwable $e) {
                    report($e);
                    $result = CheckResult::fail(
                        __('This check crashed unexpectedly — that is itself a problem worth reporting.'),
                        __('Ask the technical team to look at the logs (:type).', ['type' => class_basename($e)]),
                    );
                }

                $summary[$result->status->value]++;
                $lines[] = [
                    'key' => $check->key(),
                    'label' => $check->label(),
                    'status' => $result->status->value,
                    'message' => $result->message,
                    'fix' => $result->fix,
                ];
            }

            $categories[] = ['key' => $key, 'label' => __($label), 'checks' => $lines];
        }

        return ['categories' => $categories, 'summary' => $summary];
    }
}

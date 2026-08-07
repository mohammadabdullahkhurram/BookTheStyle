<?php

namespace App\Services\Health;

/**
 * One health check. Implementations are SMALL, READ-ONLY classes — the one
 * sanctioned mutation in the whole health check is the test booking on the
 * disposable is_test records (TestBookingSucceeds). A check must never
 * mutate real data, email real clients, or trigger real jobs.
 *
 * Adding a check = one class implementing this + one line in
 * HealthCheckRegistry::CHECKS under its category.
 */
interface HealthCheck
{
    /** Stable machine key (kebab-case). */
    public function key(): string;

    /** Short human label for the report line. */
    public function label(): string;

    public function run(HealthContext $context): CheckResult;
}

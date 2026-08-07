<?php

namespace App\Services\Health;

use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;

/**
 * Shared state for one health-check run: the salon plus the disposable
 * test records, and anything one check finds that a later check reuses
 * (the availability check hands its found slot to the booking check).
 */
final class HealthContext
{
    /** @var array{date: string, time: string, spoken: string}|null */
    public ?array $slot = null;

    public function __construct(
        public readonly Salon $salon,
        // Null in the scheduled monitor's read-only pass — the checks that
        // need these are skipped there (NeedsTestRecords).
        public readonly ?User $testStylist = null,
        public readonly ?Service $testService = null,
        public readonly ?Client $testClient = null,
    ) {}
}

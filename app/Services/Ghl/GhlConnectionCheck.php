<?php

namespace App\Services\Ghl;

/**
 * The outcome of a "Test connection" run, in UI-ready form.
 */
final readonly class GhlConnectionCheck
{
    public function __construct(
        public bool $ok,
        public string $message,
        public int $calendarCount = 0,
    ) {}
}

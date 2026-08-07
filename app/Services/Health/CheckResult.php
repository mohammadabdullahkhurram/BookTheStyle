<?php

namespace App\Services\Health;

/**
 * One check's outcome: the status, a PLAIN-LANGUAGE message a non-engineer
 * can read, and (when not passing) a concrete "what to do" hint.
 */
final readonly class CheckResult
{
    public function __construct(
        public CheckStatus $status,
        public string $message,
        public ?string $fix = null,
    ) {}

    public static function pass(string $message): self
    {
        return new self(CheckStatus::Pass, $message);
    }

    public static function warn(string $message, ?string $fix = null): self
    {
        return new self(CheckStatus::Warn, $message, $fix);
    }

    public static function fail(string $message, ?string $fix = null): self
    {
        return new self(CheckStatus::Fail, $message, $fix);
    }
}

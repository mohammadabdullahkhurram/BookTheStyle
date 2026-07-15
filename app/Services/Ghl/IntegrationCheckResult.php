<?php

namespace App\Services\Ghl;

/**
 * The UI-ready outcome of one integration check: passed / failed with a
 * specific human message, optional per-item detail rows (e.g. one line per
 * stylist mapping), a likely-fix hint on failure, and a blocked state for
 * checks that need the deployed public URL. Persisted (as toArray()) in
 * salons.integration_checks so "Last verified X ago" survives navigation.
 * Never carries tokens or client PII.
 */
final readonly class IntegrationCheckResult
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    /**
     * @param  list<array{ok: bool, text: string}>  $details
     */
    public function __construct(
        public string $state,
        public string $message,
        public ?string $hint = null,
        public array $details = [],
    ) {}

    /** @param list<array{ok: bool, text: string}> $details */
    public static function passed(string $message, array $details = []): self
    {
        return new self(self::PASSED, $message, null, $details);
    }

    /** @param list<array{ok: bool, text: string}> $details */
    public static function failed(string $message, ?string $hint = null, array $details = []): self
    {
        return new self(self::FAILED, $message, $hint, $details);
    }

    public static function blocked(string $message): self
    {
        return new self(self::BLOCKED, $message);
    }

    public function ok(): bool
    {
        return $this->state === self::PASSED;
    }

    /**
     * @return array{state: string, message: string, hint: string|null, details: list<array{ok: bool, text: string}>, at: string}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'message' => $this->message,
            'hint' => $this->hint,
            'details' => $this->details,
            'at' => now()->toIso8601String(),
        ];
    }
}

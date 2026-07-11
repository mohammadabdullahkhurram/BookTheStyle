<?php

namespace App\Services\BookingApi;

use Exception;

/**
 * A clean, speakable Booking API failure. Rendered upstream as JSON with a
 * voice-friendly message — never a stack trace. The error code separates
 * API/validation failures from engine refusals in logs and responses.
 */
class ApiError extends Exception
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $speakable,
        public readonly string $errorCode,
        public readonly array $extra = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($speakable);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function validation(string $speakable, string $errorCode, array $extra = []): self
    {
        return new self($speakable, $errorCode, $extra);
    }

    /** @return array<string, mixed> */
    public function toResponse(): array
    {
        return [
            'success' => false,
            'error' => $this->errorCode,
            'message' => $this->speakable,
            ...$this->extra,
        ];
    }
}

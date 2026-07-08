<?php

namespace App\Services\Ghl;

use RuntimeException;

/**
 * A GoHighLevel API failure translated into a stable reason plus a message
 * safe to show in the UI. Never carries the token, the raw response body, or
 * anything else that could leak a secret into a page or log.
 */
class GhlApiException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const UNAUTHORIZED = 'unauthorized';

    public const NOT_FOUND = 'not_found';

    public const RATE_LIMITED = 'rate_limited';

    public const SERVER = 'server';

    public const NETWORK = 'network';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, __('Add the location ID and private integration token first.'));
    }

    public static function network(): self
    {
        return new self(self::NETWORK, __('Could not reach GoHighLevel. Check your internet connection and try again.'));
    }

    public static function rateLimited(): self
    {
        return new self(self::RATE_LIMITED, __('GoHighLevel is rate-limiting requests right now. Wait a moment and try again.'));
    }

    public static function fromStatus(int $status): self
    {
        return match (true) {
            $status === 401 || $status === 403 => new self(
                self::UNAUTHORIZED,
                __('GoHighLevel rejected the credentials — check the private integration token and that it belongs to this location.'),
            ),
            $status === 400 || $status === 404 || $status === 422 => new self(
                self::NOT_FOUND,
                __('GoHighLevel could not find that resource — check the location ID.'),
            ),
            $status === 429 => self::rateLimited(),
            default => new self(
                self::SERVER,
                __('GoHighLevel returned an unexpected error (HTTP :status). Try again shortly.', ['status' => $status]),
            ),
        };
    }
}

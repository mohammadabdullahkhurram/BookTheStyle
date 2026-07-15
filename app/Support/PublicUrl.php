<?php

namespace App\Support;

/**
 * Whether a URL is plausibly reachable from the public internet — the gate
 * for integration checks that call the app back over its own URL (webhook
 * delivery, booking API). Local hosts get an honest "needs the live URL"
 * state instead of a false failure; once the app is deployed with a public
 * APP_URL the same buttons work unchanged.
 */
final class PublicUrl
{
    public static function isPublic(?string $url): bool
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        if ($host === '' || $host === 'localhost' || $host === '::1' || $host === '[::1]') {
            return false;
        }

        foreach (['.test', '.localhost', '.local'] as $localTld) {
            if (str_ends_with($host, $localTld)) {
                return false;
            }
        }

        // lvh.me and friends resolve to 127.0.0.1 — local by construction.
        if ($host === 'lvh.me' || str_ends_with($host, '.lvh.me')) {
            return false;
        }

        // Literal private / reserved / loopback IPs.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}

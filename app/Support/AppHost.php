<?php

namespace App\Support;

/**
 * Host-correct absolute URLs for the four-way host split. APP_URL is the
 * APEX (marketing) — it is the url()/asset() root and nothing more. Anything
 * that actually lives on another host must NEVER be built by gluing a path
 * onto APP_URL: that is exactly the class of bug that 404'd every password
 * reset in production (auth lives on app.{domain}, the link landed on the
 * apex). Derives from config (never env() — config:cache safe): scheme and
 * port come from APP_URL, the host from APP_DOMAIN plus the subdomain.
 *
 * Prefer an absolute route() where a named, domain-bound route exists (it
 * proves the URL against the real route table). Use this helper where a URL
 * is composed for machine use/tests and the flexibility of config-derived
 * hosts matters (e.g. the integration self-checks).
 */
final class AppHost
{
    /** The application host: app.{APP_DOMAIN} — auth, /webhooks, /cal, /api. */
    public static function app(string $path = ''): string
    {
        return self::forSubdomain('app', $path);
    }

    /** A salon tenant host: {slug}.{APP_DOMAIN}. */
    public static function salon(string $slug, string $path = ''): string
    {
        return self::forSubdomain($slug, $path);
    }

    public static function forSubdomain(string $subdomain, string $path = ''): string
    {
        $base = parse_url((string) config('app.url'));
        $scheme = $base['scheme'] ?? 'https';
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        return $scheme.'://'.$subdomain.'.'.config('app.domain').$port
            .($path !== '' ? '/'.ltrim($path, '/') : '');
    }
}

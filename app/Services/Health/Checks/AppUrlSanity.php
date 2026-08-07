<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

class AppUrlSanity implements HealthCheck
{
    public function key(): string
    {
        return 'app-url';
    }

    public function label(): string
    {
        return __('App URL & hosts');
    }

    public function run(HealthContext $context): CheckResult
    {
        $appUrl = (string) config('app.url');
        $domain = (string) config('app.domain');
        $host = (string) parse_url($appUrl, PHP_URL_HOST);
        $scheme = (string) parse_url($appUrl, PHP_URL_SCHEME);

        if ($domain === '' || $host === '' || ! str_ends_with($host, $domain)) {
            return CheckResult::fail(
                __('APP_URL (:url) does not match APP_DOMAIN (:domain) — links, widget embeds, and the API URLs the docs show would be wrong.', ['url' => $appUrl, 'domain' => $domain]),
                __('Align APP_URL and APP_DOMAIN in the server .env, then rebuild caches.'),
            );
        }

        if (app()->environment('production') && $scheme !== 'https') {
            return CheckResult::fail(
                __('APP_URL is not https in production — cookies and generated links will misbehave behind Cloudflare.'),
                __('Set APP_URL to https:// in the server .env, then rebuild caches.'),
            );
        }

        return CheckResult::pass(__('APP_URL and APP_DOMAIN agree (:host) — generated links and hosts are consistent.', ['host' => $host]));
    }
}

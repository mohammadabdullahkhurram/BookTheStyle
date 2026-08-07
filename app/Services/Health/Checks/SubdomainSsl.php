<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use App\Support\AppHost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The salon's own subdomain resolves AND serves a valid HTTPS certificate —
 * a real TLS-verified request, not a config read. This is the direct guard
 * against the known failure mode: Cloudflare Full (strict) answers 525 for
 * every visitor when the origin has no certificate for the host (hostnames
 * are created by hand in hPanel, never by code — golden rule 10). Read-only:
 * one GET; ANY HTTP answer proves DNS + TLS, whatever the status code.
 */
class SubdomainSsl implements HealthCheck
{
    public function key(): string
    {
        return 'subdomain-ssl';
    }

    public function label(): string
    {
        return __('Salon address & SSL');
    }

    public function run(HealthContext $context): CheckResult
    {
        $url = AppHost::salon($context->salon->slug);

        if (! Str::startsWith($url, 'https://')) {
            return CheckResult::warn(
                __('The app is not running on HTTPS here (:url), so the certificate cannot be verified from this environment. In production this check does the real thing.', ['url' => $url]),
            );
        }

        try {
            // Guzzle verifies the certificate chain by default — a completed
            // request IS the proof. 525/526 are Cloudflare telling visitors
            // the ORIGIN certificate is broken, so they still fail loudly.
            $response = Http::timeout(8)->get($url);

            if (in_array($response->status(), [525, 526], true)) {
                return CheckResult::fail(
                    __('Cloudflare answers :status on :url — the salon\'s address is broken for every visitor because the origin has no valid certificate for this hostname.', ['status' => $response->status(), 'url' => $url]),
                    __('The subdomain must be created by hand in hPanel (which issues its origin certificate) — never by code. Check it exists there, then re-run.'),
                );
            }

            return CheckResult::pass(__('The salon\'s address :url resolves and serves a valid HTTPS certificate — visitors reach it securely.', ['url' => $url]));
        } catch (\Throwable $e) {
            $reason = $e->getMessage();

            if (Str::contains($reason, ['SSL', 'certificate', 'cert'], ignoreCase: true)) {
                return CheckResult::fail(
                    __('The address :url answers, but its HTTPS certificate is invalid — browsers will block visitors with a security warning.', ['url' => $url]),
                    __('Re-issue the certificate for this subdomain in hPanel (SSL section) and confirm Cloudflare covers the hostname.'),
                );
            }

            if (Str::contains($reason, ['resolve', 'getaddrinfo', 'Name or service not known'], ignoreCase: true)) {
                return CheckResult::fail(
                    __('The salon\'s address :url does not resolve — the subdomain does not exist in DNS, so clients cannot reach the widget or booking page at all.', ['url' => $url]),
                    __('Create the subdomain by hand in hPanel and add it in Cloudflare (proxied). Hostnames are never created by the app.'),
                );
            }

            return CheckResult::fail(
                __('The salon\'s address :url did not answer (:reason).', ['url' => $url, 'reason' => Str::limit($reason, 120)]),
                __('Check the subdomain in hPanel and its Cloudflare DNS record.'),
            );
        }
    }
}

<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

/**
 * Read-only mail validation: transport + from-address sanity. Deliberately
 * NO test email is sent — the test client's address is @bluejaypro.invalid
 * (undeliverable by design) and sending would queue a real mail job; the
 * config check catches the failure modes that have actually bitten.
 */
class MailTransportConfigured implements HealthCheck
{
    public function key(): string
    {
        return 'mail-transport';
    }

    public function label(): string
    {
        return __('Email sending');
    }

    public function run(HealthContext $context): CheckResult
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        if (blank($from)) {
            return CheckResult::fail(
                __('No from-address is configured — invites, password resets, and booking mail cannot send.'),
                __('Set MAIL_FROM_ADDRESS in the server .env, then re-run the deploy cache rebuild.'),
            );
        }

        if (app()->environment('production') && in_array($mailer, ['log', 'array'], true)) {
            return CheckResult::fail(
                __('The mailer is set to ":mailer" — emails are being discarded, not sent.', ['mailer' => $mailer]),
                __('Set a real MAIL_MAILER (SMTP) in the server .env, then re-run the deploy cache rebuild.'),
            );
        }

        return CheckResult::pass(__('App email is configured (:mailer transport, sending as :from) — invites, password resets, and staff mail go through this.', [
            'mailer' => $mailer,
            'from' => $from,
        ]));
    }
}

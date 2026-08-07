<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use Illuminate\Support\Facades\Http;

class BookingEndpointReachable implements HealthCheck
{
    public function key(): string
    {
        return 'booking-endpoint';
    }

    public function label(): string
    {
        return __('Booking endpoint');
    }

    public function run(HealthContext $context): CheckResult
    {
        $url = route('api.booking.availability');

        try {
            $response = Http::timeout(8)->withToken('btsk_0_'.str_repeat('0', 40))->post($url, []);

            if ($response->status() === 401) {
                return CheckResult::pass(__('The public booking endpoint answers and rejects bad credentials correctly — GHL can reach it.'));
            }

            return CheckResult::fail(
                __('The endpoint answered with an unexpected status (:status) — a proxy or firewall rule may be interfering.', ['status' => $response->status()]),
                __('Check the Cloudflare WAF skip list for /api/v1/booking/* (docs/DEPLOY.md).'),
            );
        } catch (\Throwable) {
            return CheckResult::fail(
                __('The public booking endpoint did not answer at :url — GHL will not reach it either.', ['url' => $url]),
                __('Check DNS/Cloudflare for the app host.'),
            );
        }
    }
}

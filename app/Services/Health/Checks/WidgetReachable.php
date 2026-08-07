<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use Illuminate\Support\Facades\Http;

class WidgetReachable implements HealthCheck
{
    public function key(): string
    {
        return 'widget';
    }

    public function label(): string
    {
        return __('Booking widget');
    }

    public function run(HealthContext $context): CheckResult
    {
        $url = route('salon.widget.services', ['salon' => $context->salon->slug]);

        try {
            $response = Http::timeout(8)->get($url);

            // 429 = the widget's own rate limiter answered — alive and
            // reachable, which is exactly what this check proves.
            if ($response->successful() || $response->status() === 429) {
                return CheckResult::pass(__('The public widget answers on the salon\'s own address — the embed on their website will work.'));
            }

            return CheckResult::fail(
                __('The widget endpoint answered :status on :url.', ['status' => $response->status(), 'url' => $url]),
                __('Check that the salon\'s subdomain exists in hPanel and is proxied by Cloudflare.'),
            );
        } catch (\Throwable) {
            return CheckResult::fail(
                __('The widget did not answer at :url.', ['url' => $url]),
                __('Usually the salon\'s subdomain is missing in hPanel — hostnames are created by hand, never by the app.'),
            );
        }
    }
}

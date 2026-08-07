<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

/**
 * In production the deploy MUST leave config + route caches built (a
 * missing route cache has taken the site down before). Outside production
 * uncached is the normal state.
 */
class CachesBuilt implements HealthCheck
{
    public function key(): string
    {
        return 'caches';
    }

    public function label(): string
    {
        return __('Config & route caches');
    }

    public function run(HealthContext $context): CheckResult
    {
        $config = app()->configurationIsCached();
        $routes = app()->routesAreCached();

        if (! app()->environment('production')) {
            return CheckResult::pass(__('Not production — caches are optional here (config: :config, routes: :routes).', [
                'config' => $config ? __('cached') : __('live'),
                'routes' => $routes ? __('cached') : __('live'),
            ]));
        }

        if (! $config || ! $routes) {
            return CheckResult::fail(
                __('Production is running WITHOUT :which cache — the deploy did not finish its rebuild. This exact gap has caused an outage before.', [
                    'which' => ! $config && ! $routes ? __('the config or route') : (! $config ? __('the config') : __('the route')),
                ]),
                __('Run the full deploy cache sequence + opcache reset (docs/DEPLOY.md).'),
            );
        }

        return CheckResult::pass(__('Config and route caches are built — the deploy rebuild completed.'));
    }
}

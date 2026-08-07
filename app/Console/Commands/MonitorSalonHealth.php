<?php

namespace App\Console\Commands;

use App\Models\Salon;
use App\Services\Health\HealthCheckRegistry;
use App\Services\Health\HealthMonitor;
use Illuminate\Console\Command;

/**
 * The scheduled health monitor: runs the READ-ONLY checks against every
 * LIVE salon (active, onboarded, not demo), records each run, and lets
 * HealthMonitor fire the green→red alert. Strictly no mutation: the
 * registry's monitor mode skips the checks that need the disposable test
 * records (availability probe + test booking), creates nothing, books
 * nothing, and emails nobody except agency admins on a regression.
 * Pre-live salons are skipped — their checks flip constantly during setup
 * and the operator watches the page directly; the manual run covers them.
 */
class MonitorSalonHealth extends Command
{
    protected $signature = 'health:monitor';

    protected $description = 'Run the read-only health checks for every live salon, record history, and alert on green-to-red transitions';

    public function handle(HealthCheckRegistry $registry, HealthMonitor $monitor): int
    {
        $salons = Salon::query()
            ->where('active', true)
            ->where('is_demo', false)
            ->whereNotNull('onboarded_at')
            ->get();

        foreach ($salons as $salon) {
            try {
                $report = $registry->run($salon, forMonitor: true);
                $run = $monitor->record($salon, $report, HealthMonitor::SOURCE_SCHEDULED);

                $this->line(sprintf(
                    '%s: %d passed, %d warnings, %d failed%s',
                    $salon->slug,
                    $run->pass_count,
                    $run->warn_count,
                    $run->fail_count,
                    $run->regressions !== null ? ' — ALERT: '.count($run->regressions).' newly failing' : '',
                ));
            } catch (\Throwable $e) {
                report($e); // one broken salon must not stop the sweep
                $this->error($salon->slug.': monitor run crashed — '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}

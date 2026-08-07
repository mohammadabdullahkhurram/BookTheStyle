<?php

namespace App\Console\Commands;

use App\Models\Salon;
use App\Models\User;
use App\Services\Diagnostics\ConnectionDiagnostics;
use Illuminate\Console\Command;

/**
 * Remove ABANDONED connection-check test records: someone ran "Check
 * connections", walked away, and never pressed "Finish & clean up". The
 * records are invisible to clients regardless (is_test exclusions), but a
 * live salon must not keep a phantom test stylist around — so anything
 * older than the window is torn down exactly like the button would.
 * Scheduled hourly; idempotent; touches ONLY is_test records.
 */
class SweepDiagnosticsTestRecords extends Command
{
    protected $signature = 'diagnostics:sweep-test-records';

    protected $description = 'Tear down connection-check test records older than the abandonment window';

    public function handle(ConnectionDiagnostics $diagnostics): int
    {
        $cutoff = now()->subHours(ConnectionDiagnostics::SWEEP_AFTER_HOURS);

        $salonIds = User::withTrashed()
            ->where('users.is_test', true)
            ->where('users.created_at', '<', $cutoff)
            ->where('users.email', 'like', 'diagnostics+%@bluejaypro.invalid')
            ->join('salon_memberships', 'salon_memberships.user_id', '=', 'users.id')
            ->pluck('salon_memberships.salon_id')
            ->unique();

        foreach ($salonIds as $salonId) {
            $salon = Salon::query()->whereKey((int) $salonId)->first();

            if ($salon !== null) {
                $diagnostics->teardown($salon);
                $this->info("Swept abandoned test records for salon {$salon->id} ({$salon->name}).");
            }
        }

        $this->info('Sweep complete: '.$salonIds->count().' salon(s) cleaned.');

        return self::SUCCESS;
    }
}

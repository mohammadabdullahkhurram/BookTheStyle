<?php

namespace App\Console\Commands;

use App\Actions\Demo\DeleteDemoSalon;
use App\Models\Salon;
use Illuminate\Console\Command;

/**
 * Hard-delete expired demo salons and everything under them. Runs hourly on
 * the single cron; touches ONLY salons flagged is_demo with a past expiry —
 * DeleteDemoSalon refuses anything else outright.
 */
class SweepDemoSalons extends Command
{
    protected $signature = 'demo:sweep';

    protected $description = 'Hard-delete expired demo salons (and their demo accounts)';

    public function handle(DeleteDemoSalon $delete): int
    {
        $expired = Salon::query()
            ->where('is_demo', true)
            ->where('demo_expires_at', '<', now())
            ->get();

        foreach ($expired as $salon) {
            $delete->handle($salon);
        }

        $this->components->info($expired->isEmpty()
            ? 'No expired demo salons.'
            : 'Swept '.$expired->count().' expired demo salon(s).');

        return self::SUCCESS;
    }
}

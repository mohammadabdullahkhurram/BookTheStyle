<?php

namespace App\Console\Commands;

use App\Actions\Demo\DeleteDemoSalon;
use App\Support\DemoMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Restores the demo showcase to its seeded baseline. Demo visitors can
 * CREATE bookings (the one try-it exemption in the otherwise view-only
 * guest demo), and the showcase is one shared salon — so this runs daily to
 * clear visitor-created bookings and re-seed a fresh, current calendar.
 *
 * Implementation: hard-delete the showcase (DeleteDemoSalon refuses
 * anything that is not flagged is_demo — real salons are structurally
 * untouchable here) and re-run the idempotent demo:seed-showcase. A side
 * benefit: the seeded week re-anchors to "now", so the demo calendar always
 * looks current. Idempotent: with no showcase present it simply seeds.
 */
class ResetDemoShowcase extends Command
{
    protected $signature = 'demo:reset-showcase';

    protected $description = 'Restore the demo showcase salon to its seeded baseline (clears visitor-created bookings)';

    public function handle(DeleteDemoSalon $delete): int
    {
        $showcase = DemoMode::showcaseSalon();

        if ($showcase !== null) {
            $delete->handle($showcase);
            $this->info('Showcase cleared.');
        }

        Artisan::call('demo:seed-showcase', [], $this->output);

        return self::SUCCESS;
    }
}

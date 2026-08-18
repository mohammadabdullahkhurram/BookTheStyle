<?php

namespace App\Console\Commands;

use App\Enums\SalonType;
use App\Models\Agency;
use App\Models\Salon;
use App\Services\Demo\DemoSalonBuilder;
use App\Support\DemoMode;
use App\Support\TemporaryPassword;
use Illuminate\Console\Command;

/**
 * Seeds (or refreshes the presence of) THE canonical demo showcase salon —
 * the single fixed salon the public demo host renders as a guest preview.
 *
 * Idempotent and production-safe by design: firstOrCreate on the fixed
 * slug, data populated only when the salon is empty, nothing destructive
 * anywhere. Runs on deploy; re-running is a no-op. All seeded people and
 * contacts are fictional (.invalid domains, 555 numbers) and the staff
 * accounts carry a random unpublished password — nobody ever logs in as
 * them; ResolveSalon only uses the owner as the request-scoped viewer.
 */
class SeedDemoShowcase extends Command
{
    protected $signature = 'demo:seed-showcase';

    protected $description = 'Create the canonical demo showcase salon if it does not exist (idempotent, additive)';

    public function handle(): int
    {
        $agency = Agency::query()->firstOrCreate(
            ['is_demo' => true],
            ['name' => 'BookTheStyle demo'],
        );

        $salon = Salon::query()->firstOrCreate(
            ['slug' => DemoMode::SHOWCASE_SLUG],
            [
                'agency_id' => $agency->id,
                'name' => 'Glamour Studio',
                'timezone' => 'America/Los_Angeles',
                'currency' => 'USD',
                'active' => true,
                'salon_type' => SalonType::Mix->value,
                'app_theme' => 'marble',
                'branding' => ['accent' => '#824C71'],
                'legal_business_name' => 'Glamour Studio LLC',
                'business_email' => 'hello@showcase.demo.invalid',
                'business_phone' => '+1 415 555 0100',
                'address_line1' => '210 Castro Street',
                'city' => 'San Francisco',
                'region' => 'CA',
                'postal_code' => '94114',
                'country' => 'US',
                'contact_name' => 'Olivia Owner',
                'contact_email' => 'owner@showcase.demo.invalid',
                'contact_phone' => '+1 415 555 0101',
            ],
        );

        // The showcase never expires and never carries the setup nag.
        $salon->forceFill([
            'is_demo' => true,
            'demo_expires_at' => null,
            'onboarded_at' => $salon->onboarded_at ?? now(),
        ])->save();

        // Widgets top up ALWAYS (idempotent): an already-populated showcase
        // gains newly-shipped widget types at the next deploy's seed run
        // without waiting for the nightly reset.
        $builder = new DemoSalonBuilder(
            emailDomain: 'showcase.demo.invalid',
            password: TemporaryPassword::generate(),
        );
        $builder->widgets($salon);

        // Populate once — a busy calendar, services, staff, clients. A salon
        // that already has bookings is left exactly as it is (idempotent).
        if ($salon->bookings()->doesntExist()) {
            $summary = $builder->populate($salon);

            $this->info(sprintf(
                'Showcase salon seeded: %d services, %d clients, %d bookings.',
                $summary['services'],
                $summary['clients'],
                $summary['bookings'],
            ));

            return self::SUCCESS;
        }

        $this->info('Showcase salon already present — nothing to do.');

        return self::SUCCESS;
    }
}

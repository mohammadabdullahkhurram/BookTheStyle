<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Availability;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Services\Demo\DemoSalonBuilder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A COMPLETE, realistic demo salon for UI/UX review — every screen has data:
 * staff with varied weekly hours (+ time off and a date-specific day), a
 * priced service menu with per-stylist duration overrides, a rich client
 * book, and three weeks of bookings around "now" (completed / no-shows /
 * cancelled / today's check-ins / upcoming, multi-service visits, every
 * source) so Today, Calendar, Appointments, Check-in, Clients, Services,
 * Staff, Availability, Reports and Widgets all render with real content.
 *
 * STRICTLY ADDITIVE and idempotent: it creates the demo salon under the
 * Bluejaypro agency once (keyed by the slug) and NEVER touches anything
 * else — re-running when the salon exists is a no-op. Never pair with
 * migrate:fresh (forbidden repo-wide; see CLAUDE.md) — run it on whatever
 * database you have:
 *
 *   php artisan db:seed --class=DemoSalonSeeder
 *
 * Login printed at the end: owner@demo.test / password (plus front desk +
 * one stylist account).
 */
class DemoSalonSeeder extends Seeder
{
    use WithoutModelEvents;

    private const PASSWORD = 'password';

    // 'demo' is reserved: demo.{domain} is the PUBLIC demo entry point.
    private const SLUG = 'glamour';

    public function run(): void
    {
        // LOCAL/DEV ONLY: every demo account ships the literal password
        // "password" with no forced change. Running this against the live
        // database would create weak-credential logins — refuse outright.
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSalonSeeder is local/dev-only — it creates demo accounts with weak passwords and must never run in production.');
        }

        if (Salon::query()->where('slug', self::SLUG)->exists()) {
            $this->say('Demo salon "'.self::SLUG.'" already exists — nothing created (the seeder is additive and idempotent).');

            return;
        }

        $agency = Agency::firstOrCreate(['name' => 'Bluejaypro']);

        $salon = Salon::create([
            'agency_id' => $agency->id,
            'name' => 'Glamour Studio',
            'slug' => self::SLUG,
            'timezone' => 'America/Los_Angeles',
            'currency' => 'USD',
            'active' => true,
            'app_theme' => 'marble',
            'branding' => ['accent' => '#824C71'],
            'legal_business_name' => 'Glamour Studio LLC',
            'business_email' => 'hello@glamourstudio.test',
            'business_phone' => '+1 415 555 0100',
            'address_line1' => '210 Castro Street',
            'city' => 'San Francisco',
            'region' => 'CA',
            'postal_code' => '94114',
            'country' => 'US',
            'contact_name' => 'Olivia Owner',
            'contact_email' => 'owner@demo.test',
            'contact_phone' => '+1 415 555 0101',
        ]);
        // Not fillable (set only by the wizard normally): skip the setup nag.
        $salon->forceFill(['onboarded_at' => now()])->save();

        $summary = (new DemoSalonBuilder('demo.test', self::PASSWORD))->populate($salon);
        $stylists = $summary['stylists'];
        $services = $summary['services'];
        $clients = $summary['clients'];
        $bookings = $summary['bookings'];
        $widget = $salon->defaultWidget();

        $this->say('Seeded the demo salon "'.$salon->name.'" — everything below is ADDITIVE (nothing was reset).');
        $this->table(['What', 'Detail'], [
            ['Salon', $salon->name.' · '.self::SLUG.'.'.config('app.domain').' ('.$salon->timezone.')'],
            ['App URL', 'http://'.self::SLUG.'.'.config('app.domain')],
            ['Widget', 'http://'.self::SLUG.'.'.config('app.domain').'/widget/'.$widget->public_id],
            ['Stylists', (string) count($stylists)],
            ['Services', (string) $services],
            ['Clients', (string) $clients],
            ['Bookings', $bookings.' (past + today + upcoming; all statuses and sources)'],
        ]);
        $this->table(['Email', 'Password', 'Role'], [
            ['owner@demo.test', self::PASSWORD, 'Salon owner'],
            ['frontdesk@demo.test', self::PASSWORD, 'Front desk'],
            ['maya@demo.test', self::PASSWORD, 'Stylist'],
        ]);
    }

    private function say(string $message): void
    {
        // $command is typed non-nullable but stays uninitialized when the
        // seeder runs outside artisan (tests, programmatic runs).
        // @phpstan-ignore isset.property
        if (isset($this->command)) {
            $this->command->info($message);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function table(array $headers, array $rows): void
    {
        // @phpstan-ignore isset.property
        if (isset($this->command)) {
            $this->command->table($headers, $rows);
        }
    }
}

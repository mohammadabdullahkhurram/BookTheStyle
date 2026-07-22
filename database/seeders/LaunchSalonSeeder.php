<?php

namespace Database\Seeders;

use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Demo\DemoSalonBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The LAUNCH-VIDEO salon: the fixture every marketing capture shoots against
 * (scripts/capture-launch-assets.mjs). Same data engine as the demo salon
 * (DemoSalonBuilder) with two differences that exist only for film:
 *
 *  1. Every relative date is pinned to ANCHOR — never now() — so the
 *     calendar, dashboard, and reports look IDENTICAL on every run and
 *     screenshots are reproducible frame-for-frame. Capture runs the local
 *     server with APP_FAKE_NOW set to the same instant (config/app.php)
 *     so the app's "today" agrees with the data.
 *  2. The dataset is deepened for the camera: a 10-service menu, a branded
 *     placeholder logo, and extra bookings so the reports charts have shape.
 *
 * ALL DATA IS FICTIONAL — invented names, 555 phone numbers, .test email
 * domains. STRICTLY ADDITIVE and idempotent: creates its own salon once
 * (keyed by SLUG), never touches anything else, re-running is a no-op.
 * Never pair with migrate:fresh (forbidden repo-wide; see CLAUDE.md):
 *
 *   php artisan db:seed --class=LaunchSalonSeeder
 */
class LaunchSalonSeeder extends Seeder
{
    use WithoutModelEvents;

    /** The tenant subdomain (…and the asset filenames' salon identity). */
    public const SLUG = 'marlowe-sage';

    /**
     * The frozen "now" — salon-local wall time. Tuesday mid-morning, so the
     * dashboard is mid-flow and the week reads busy in both directions.
     * Run the capture server with APP_FAKE_NOW set to exactly this.
     */
    public const ANCHOR = '2026-09-15 10:20';

    public const TIMEZONE = 'America/Los_Angeles';

    /** Baseline brand accent (the DESIGN-TOKENS plum). The capture script
     *  swaps this per accent variant via `php artisan launch:capture style`. */
    public const ACCENT = '#824C71';

    /** Fixed widget embed id so capture URLs survive a re-clone. Public, not a secret. */
    public const WIDGET_PUBLIC_ID = 'marlowesagebooking01';

    public const OWNER_EMAIL = 'owner@marlowe-sage.test';

    private const PASSWORD = 'password';

    private const NAME = 'Marlowe & Sage';

    public function run(): void
    {
        // Weak demo credentials — local/dev-only, same rule as DemoSalonSeeder.
        if (app()->isProduction()) {
            throw new RuntimeException('LaunchSalonSeeder is local/dev-only — it creates accounts with weak passwords and must never run in production.');
        }

        if (Salon::query()->where('slug', self::SLUG)->exists()) {
            $this->say('Launch salon "'.self::SLUG.'" already exists — nothing created (the seeder is additive and idempotent).');

            return;
        }

        $agency = Agency::firstOrCreate(['name' => 'Bluejaypro']);
        $anchor = CarbonImmutable::parse(self::ANCHOR, self::TIMEZONE);

        $salon = Salon::create([
            'agency_id' => $agency->id,
            'name' => self::NAME,
            'slug' => self::SLUG,
            'timezone' => self::TIMEZONE,
            'currency' => 'USD',
            'active' => true,
            'app_theme' => 'marble',
            'branding' => ['accent' => self::ACCENT],
            'legal_business_name' => 'Marlowe & Sage Salon LLC',
            'business_email' => 'hello@marlowe-sage.test',
            'business_phone' => '+1 415 555 0140',
            'address_line1' => '48 Juniper Row',
            'city' => 'Portland',
            'region' => 'OR',
            'postal_code' => '97209',
            'country' => 'US',
            'contact_name' => 'Olivia Owner',
            'contact_email' => self::OWNER_EMAIL,
            'contact_phone' => '+1 415 555 0141',
        ]);
        // Not fillable (set only by the wizard normally): skip the setup nag.
        $salon->forceFill(['onboarded_at' => $anchor->subWeeks(6)])->save();

        $salon->forceFill(['branding' => ['accent' => self::ACCENT, 'logo_path' => $this->logo($salon)]])->save();

        $summary = (new DemoSalonBuilder('marlowe-sage.test', self::PASSWORD, $anchor))->populate($salon);
        $this->extendMenu($salon, $summary['stylists'], $summary['owner'], $anchor);

        $salon->widgets()->firstOrCreate(
            ['public_id' => self::WIDGET_PUBLIC_ID],
            ['name' => 'Booking widget', 'type' => 'booking', 'branding' => null, 'theme' => 'marble'],
        );

        $this->say('Seeded the launch salon "'.self::NAME.'" — anchored to '.self::ANCHOR.' '.self::TIMEZONE.' (additive; nothing was reset).');
        $this->say('Owner login: '.self::OWNER_EMAIL.' / '.self::PASSWORD);
    }

    /**
     * A placeholder wordmark logo on the public disk (the path the branding
     * uploader would have written). SVG, generated here — no binary in git.
     */
    private function logo(Salon $salon): string
    {
        $path = 'branding/'.$salon->id.'/logo.svg';

        Storage::disk('public')->put($path, <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" width="380" height="96" viewBox="0 0 380 96">
              <g fill="none" stroke="#824C71" stroke-width="3" stroke-linecap="round">
                <path d="M24 72 C 20 48, 34 26, 48 24 C 60 23, 64 34, 56 42 C 48 50, 34 48, 32 36"/>
                <path d="M48 24 C 56 14, 70 12, 76 18"/>
              </g>
              <text x="100" y="52" font-family="Georgia, serif" font-size="34" font-weight="600" fill="#211C18">Marlowe &amp; Sage</text>
              <text x="102" y="76" font-family="Helvetica, Arial, sans-serif" font-size="13" letter-spacing="4" fill="#824C71">SALON · PORTLAND</text>
            </svg>
            SVG);

        return $path;
    }

    /**
     * Deepen the menu past the shared five-service base (the camera lingers
     * on the service list) and give the new services their own bookings so
     * the reports mix isn't dominated by the base menu.
     *
     * @param  list<User>  $stylists
     */
    private function extendMenu(Salon $salon, array $stylists, User $owner, CarbonImmutable $anchor): void
    {
        [$maya, $sofia, $jonah, $elise] = $stylists;

        $menu = [
            // name, minutes, price cents, qualified stylists
            ['Root touch-up', 75, 9500, [$maya, $sofia]],
            ['Gloss & tone', 45, 6500, [$sofia]],
            ['Beard trim', 25, 2500, [$jonah]],
            ['Deep conditioning ritual', 40, 5000, [$maya, $elise]],
            ['Event styling & updo', 75, 11000, [$elise, $sofia]],
        ];

        $services = [];
        foreach ($menu as [$name, $minutes, $cents, $qualified]) {
            $service = Service::create([
                'salon_id' => $salon->id,
                'name' => $name,
                'duration_min' => $minutes,
                'price_cents' => $cents,
                'active' => true,
            ]);
            foreach ($qualified as $stylist) {
                $service->stylists()->attach($stylist->id, ['salon_id' => $salon->id]);
            }
            $services[] = $service;
        }

        // A booking per extended service across the surrounding weeks: past
        // ones completed, future ones booked — reports and the calendar both
        // see the full menu. -1 and the two 0s keep the CURRENT week camera-
        // ready: Monday isn't empty and "today" reads busy. Late-afternoon
        // starts keep clear of the base dataset's slots (same stylists,
        // earlier hours).
        $clients = Client::query()->where('salon_id', $salon->id)->orderBy('id')->get()->values();
        $today = $anchor->startOfDay();

        foreach ([-11, -8, -4, -1, 0, 0, 2, 6, 9] as $index => $offset) {
            $service = $services[$index % count($services)];
            $stylist = $service->stylists()->first();
            $start = $today->addDays($offset)->setTime(16, $index % 2 === 0 ? 0 : 30);
            $status = $offset < 0 ? BookingStatus::Completed : BookingStatus::Booked;

            $booking = Booking::create([
                'salon_id' => $salon->id,
                'client_id' => $clients[($index * 5) % $clients->count()]->id,
                'status' => $status,
                'booked_by_type' => BookedByType::SalonOwner,
                'booked_by_user_id' => $owner->id,
                'source' => BookingSource::InApp,
                'is_walkin' => false,
            ]);
            $booking->items()->create([
                'salon_id' => $salon->id,
                'service_id' => $service->id,
                'stylist_id' => $stylist->id,
                'starts_at' => $start,
                'ends_at' => $start->addMinutes($service->duration_min),
                'buffer_min' => 0,
            ]);
            $booking->statusEvents()->create([
                'salon_id' => $salon->id,
                'from_status' => null,
                'to_status' => BookingStatus::Booked,
            ]);
            if ($status !== BookingStatus::Booked) {
                $booking->statusEvents()->create([
                    'salon_id' => $salon->id,
                    'from_status' => BookingStatus::Booked,
                    'to_status' => $status,
                ]);
            }
        }
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
}

<?php

namespace App\Actions\Demo;

use App\Enums\SalonType;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Services\Demo\DemoSalonBuilder;
use App\Support\TemporaryPassword;
use Illuminate\Support\Str;

/**
 * Provision one isolated, disposable demo salon for one visitor (the
 * Linear/Notion pattern — never a shared demo that visitor #3 can break).
 *
 * Every demo salon lives under the dedicated DEMO AGENCY, so the real
 * agency's console, reports, and Users tab exclude it by the same tenancy
 * scoping that separates any two agencies — no query changes to forget.
 * It is flagged is_demo (checked by every outbound path), carries NO GHL
 * connection and NO API token, its accounts live on the reserved
 * non-routable domain {slug}.demo.invalid with a random password (the
 * visitor is session-authenticated, never shown credentials), and it
 * expires for the hourly sweeper. Salon type MIX — the fullest surface.
 *
 * The random slug is a DATABASE identifier only — it is never a hostname.
 * Demo salons are reached at the static, hand-created demo.{app.domain}
 * host via the visitor's session (Salon::getRouteKey() + ResolveSalon);
 * this hosting cannot serve a runtime-minted subdomain (docs/DEPLOY.md).
 */
class ProvisionDemoSalon
{
    public const TTL_HOURS = 4;

    /** Active-demo ceiling: a public endpoint must not be able to fill the DB. */
    public const MAX_ACTIVE = 50;

    /**
     * @return array{salon: Salon, owner: User}
     */
    public function handle(): array
    {
        $agency = Agency::query()->firstOrCreate(
            ['is_demo' => true],
            ['name' => 'BookTheStyle demo'],
        );

        do {
            $slug = 'demo-'.Str::lower(Str::random(8));
        } while (Salon::query()->where('slug', $slug)->exists());

        $salon = Salon::create([
            'agency_id' => $agency->id,
            'name' => 'Glamour Studio',
            'slug' => $slug,
            'timezone' => 'America/Los_Angeles',
            'currency' => 'USD',
            'active' => true,
            'salon_type' => SalonType::Mix->value,
            'app_theme' => 'marble',
            'branding' => ['accent' => '#824C71'],
            'legal_business_name' => 'Glamour Studio LLC',
            'business_email' => 'hello@'.$slug.'.demo.invalid',
            'business_phone' => '+1 415 555 0100',
            'address_line1' => '210 Castro Street',
            'city' => 'San Francisco',
            'region' => 'CA',
            'postal_code' => '94114',
            'country' => 'US',
            'contact_name' => 'Olivia Owner',
            'contact_email' => 'owner@'.$slug.'.demo.invalid',
            'contact_phone' => '+1 415 555 0101',
        ]);
        $salon->forceFill([
            'is_demo' => true,
            'demo_expires_at' => now()->addHours(self::TTL_HOURS),
            'onboarded_at' => now(), // no setup nag inside the demo
        ])->save();

        $summary = (new DemoSalonBuilder(
            emailDomain: $slug.'.demo.invalid',
            password: TemporaryPassword::generate(),
        ))->populate($salon);

        return ['salon' => $salon, 'owner' => $summary['owner']];
    }

    public static function activeCount(): int
    {
        return Salon::query()
            ->where('is_demo', true)
            ->where('demo_expires_at', '>', now())
            ->count();
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Salon;
use App\Support\HexColor;
use Carbon\CarbonImmutable;
use Database\Seeders\LaunchSalonSeeder;
use Illuminate\Console\Command;

/**
 * Backstage control for the launch-video capture harness
 * (scripts/capture-launch-assets.mjs). Three actions:
 *
 *   prepare — seed the launch salon if missing (LaunchSalonSeeder, additive),
 *             reset its style to the baseline accent + Marble, and delete the
 *             sentinel capture client's widget bookings so a re-run books the
 *             same slot again. Prints the info JSON.
 *   info    — print the capture fixture facts as JSON (slug, widget id,
 *             anchor, APP_FAKE_NOW value, owner email).
 *   style   — set the launch salon's accent (--accent=#rrggbb) and/or app
 *             theme (--theme=marble|classic|glacier). Glacier is an
 *             agency-scoped theme the picker never offers a salon — allowed
 *             HERE deliberately, for the film's labeled theme beat only.
 *
 * LOCAL/TEST ONLY: refuses to run anywhere else. It exists so the Node
 * capture script never writes SQL — every DB mutation stays in reviewed,
 * salon-scoped PHP.
 */
class LaunchCapture extends Command
{
    /** The sentinel client the capture script books the widget flow as. */
    public const CAPTURE_CLIENT_PHONE = '+1 415 555 0999';

    protected $signature = 'launch:capture
        {action : prepare | info | style}
        {--accent= : style — accent hex, e.g. #C0613E}
        {--theme= : style — marble | classic | glacier}';

    protected $description = 'Prepare and steer the launch-video capture fixture (local only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('launch:capture is a local capture tool — refusing to run in '.app()->environment().'.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'prepare' => $this->prepare(),
            'info' => $this->info_(),
            'style' => $this->style(),
            default => $this->invalid(),
        };
    }

    private function prepare(): int
    {
        if (! Salon::query()->where('slug', LaunchSalonSeeder::SLUG)->exists()) {
            (new LaunchSalonSeeder)->run();
        }

        $salon = $this->salon();

        // Baseline style, whatever a previous capture run left behind.
        $branding = $salon->branding ?? [];
        $branding['accent'] = LaunchSalonSeeder::ACCENT;
        $salon->forceFill(['branding' => $branding, 'app_theme' => 'marble'])->save();

        // The widget confirmation shot books a real (fictional) appointment
        // as the sentinel client; remove it so the next run can book the
        // exact same slot. Scoped to the launch salon + sentinel phone only.
        Client::query()
            ->where('salon_id', $salon->id)
            ->where('phone', self::CAPTURE_CLIENT_PHONE)
            ->get()
            ->each(function (Client $client) use ($salon): void {
                $client->bookings()->where('salon_id', $salon->id)->get()->each(function ($booking): void {
                    $booking->items()->delete();
                    $booking->statusEvents()->delete();
                    $booking->delete();
                });
                $client->delete();
            });

        return $this->info_();
    }

    private function info_(): int
    {
        $salon = $this->salon();
        $anchor = CarbonImmutable::parse(LaunchSalonSeeder::ANCHOR, LaunchSalonSeeder::TIMEZONE);

        $this->line((string) json_encode([
            'slug' => $salon->slug,
            'name' => $salon->name,
            'timezone' => $salon->timezone,
            'anchor' => LaunchSalonSeeder::ANCHOR,
            'fake_now' => $anchor->toIso8601String(),
            'accent' => LaunchSalonSeeder::ACCENT,
            'theme' => $salon->app_theme,
            'owner_email' => LaunchSalonSeeder::OWNER_EMAIL,
            'widget_public_id' => LaunchSalonSeeder::WIDGET_PUBLIC_ID,
            'capture_client_phone' => self::CAPTURE_CLIENT_PHONE,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function style(): int
    {
        $salon = $this->salon();

        $accent = $this->option('accent');
        if (is_string($accent) && $accent !== '') {
            $normalized = HexColor::normalize($accent);
            if ($normalized === null) {
                $this->error('Not a hex color: '.$accent);

                return self::FAILURE;
            }
            $branding = $salon->branding ?? [];
            $branding['accent'] = $normalized;
            $salon->forceFill(['branding' => $branding]);
        }

        $theme = $this->option('theme');
        if (is_string($theme) && $theme !== '') {
            if (! in_array($theme, ['marble', 'classic', 'glacier'], true)) {
                $this->error('Unknown capture theme: '.$theme);

                return self::FAILURE;
            }
            $salon->forceFill(['app_theme' => $theme]);
        }

        $salon->save();
        $this->line((string) json_encode(['accent' => $salon->accentColor(), 'theme' => $salon->app_theme]));

        return self::SUCCESS;
    }

    private function salon(): Salon
    {
        return Salon::query()->where('slug', LaunchSalonSeeder::SLUG)->firstOrFail();
    }

    private function invalid(): int
    {
        $this->error('Unknown action — use prepare, info, or style.');

        return self::FAILURE;
    }
}

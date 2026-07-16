<?php

namespace App\Actions\Salons;

use App\Enums\AgencyRole;
use App\Mail\SalonAddedMail;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Support\SalonProfile;
use Illuminate\Support\Facades\Mail;

/**
 * Create a salon under an agency (agency console). Authorisation
 * (AgencyPolicy::manageSalons) is enforced by the caller before this runs.
 *
 * The GoHighLevel connection fields are optional at creation — a salon can be
 * created with none of them and connected later. When any are supplied they are
 * stored via UpdateGhlConnection (token encrypted, never mass-assigned).
 *
 * @phpstan-type SalonInput array{
 *     name: string, slug: string, timezone: string, accent?: string|null,
 *     allow_walkins?: bool, allow_same_day?: bool,
 *     max_advance_days?: int, min_notice_minutes?: int,
 *     ghl_location_id?: string|null, ghl_calendar_id?: string|null,
 *     ghl_token?: string|null
 * }
 */
class CreateSalon
{
    public function __construct(private UpdateGhlConnection $ghl) {}

    /**
     * @param  SalonInput  $data
     */
    public function handle(Agency $agency, array $data): Salon
    {
        $salon = $agency->salons()->create([
            'slug' => $data['slug'],
            'timezone' => $data['timezone'],
            'active' => true,
            'branding' => isset($data['accent']) && $data['accent']
                ? ['accent' => $data['accent']]
                : null,
            'allow_walkins' => $data['allow_walkins'] ?? true,
            'allow_same_day' => $data['allow_same_day'] ?? true,
            'max_advance_days' => $data['max_advance_days'] ?? 90,
            'min_notice_minutes' => $data['min_notice_minutes'] ?? 0,
            // Business + contact profile (includes the trading name).
            ...SalonProfile::attributes($data),
        ]);

        // Only create a connection row if at least one GHL field was provided.
        if (filled($data['ghl_location_id'] ?? null)
            || filled($data['ghl_calendar_id'] ?? null)
            || filled($data['ghl_token'] ?? null)) {
            $this->ghl->handle($salon, [
                'location_id' => $data['ghl_location_id'] ?? null,
                'calendar_id' => $data['ghl_calendar_id'] ?? null,
                'private_integration_token' => $data['ghl_token'] ?? null,
            ]);
        }

        $this->notifyAgencyManagers($agency, $salon);

        return $salon;
    }

    /**
     * Tell the agency's owners/admins a salon appeared under their agency
     * (queued, fail-safe — a mail hiccup never blocks salon creation).
     */
    private function notifyAgencyManagers(Agency $agency, Salon $salon): void
    {
        $managers = User::query()
            ->where('agency_id', $agency->id)
            ->whereIn('agency_role', [AgencyRole::Owner->value, AgencyRole::Admin->value])
            ->get(['id', 'name', 'email']);

        foreach ($managers as $manager) {
            rescue(fn () => Mail::to($manager->email)->send(new SalonAddedMail(
                $manager->name,
                $salon,
                $agency->name,
            )));
        }
    }
}

<?php

namespace App\Actions\Salons;

use App\Models\Agency;
use App\Models\Salon;

/**
 * Create a salon under an agency (agency console). Authorisation
 * (AgencyPolicy::manageSalons) is enforced by the caller before this runs.
 *
 * @phpstan-type SalonInput array{
 *     name: string, slug: string, timezone: string, accent?: string|null,
 *     allow_walkins?: bool, allow_same_day?: bool,
 *     max_advance_days?: int, min_notice_minutes?: int
 * }
 */
class CreateSalon
{
    /**
     * @param  SalonInput  $data
     */
    public function handle(Agency $agency, array $data): Salon
    {
        return $agency->salons()->create([
            'name' => $data['name'],
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
        ]);
    }
}

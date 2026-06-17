<?php

namespace App\Actions\Salons;

use App\Models\Salon;

/**
 * Update a salon's profile + default booking policy from the agency console.
 * Authorisation (AgencyPolicy::manageSalons) is enforced by the caller.
 */
class UpdateSalon
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Salon $salon, array $data): Salon
    {
        $branding = $salon->branding ?? [];
        if (array_key_exists('accent', $data)) {
            $branding['accent'] = $data['accent'] ?: null;
            $branding = array_filter($branding, fn ($v) => $v !== null);
        }

        $salon->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'timezone' => $data['timezone'],
            'branding' => $branding === [] ? null : $branding,
            'allow_walkins' => $data['allow_walkins'],
            'allow_same_day' => $data['allow_same_day'],
            'max_advance_days' => $data['max_advance_days'],
            'min_notice_minutes' => $data['min_notice_minutes'],
        ])->save();

        return $salon;
    }
}

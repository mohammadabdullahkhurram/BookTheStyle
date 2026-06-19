<?php

namespace App\Actions\Salons;

use App\Models\Salon;
use App\Support\SalonProfile;

/**
 * Persist a salon's business + point-of-contact profile (trading name, legal
 * name, business email/phone/website, address, primary contact). Authorisation
 * (SalonPolicy::manageProfile) is enforced by the caller. Required fields are
 * validated by the caller via SalonProfile::rules().
 */
class UpdateSalonProfile
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Salon $salon, array $data): Salon
    {
        $salon->fill(SalonProfile::attributes($data))->save();

        return $salon;
    }
}

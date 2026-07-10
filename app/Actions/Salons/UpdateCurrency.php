<?php

namespace App\Actions\Salons;

use App\Models\Salon;

/**
 * Update a salon's display currency (salon settings). Used only to FORMAT
 * service prices — the app never charges anyone. Authorisation
 * (SalonPolicy::manage) is enforced by the caller; the code is validated
 * there against Money::codes().
 */
class UpdateCurrency
{
    public function handle(Salon $salon, string $currency): Salon
    {
        $salon->update(['currency' => $currency]);

        return $salon;
    }
}

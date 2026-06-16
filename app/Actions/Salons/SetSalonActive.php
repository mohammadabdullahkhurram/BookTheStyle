<?php

namespace App\Actions\Salons;

use App\Models\Salon;

/**
 * Deactivate/reactivate a salon (no hard delete). Agency-console only.
 */
class SetSalonActive
{
    public function handle(Salon $salon, bool $active): Salon
    {
        $salon->update(['active' => $active]);

        return $salon;
    }
}

<?php

namespace App\Actions\Salons;

use App\Models\Salon;

/**
 * Update a salon's accent override (salon settings → branding). The accent feeds
 * the per-salon brandable accent token. The salon's trading name lives in the
 * business profile (UpdateSalonProfile), not here.
 */
class UpdateBranding
{
    /**
     * @param  array{accent: string|null}  $data
     */
    public function handle(Salon $salon, array $data): Salon
    {
        $branding = $salon->branding ?? [];
        $branding['accent'] = $data['accent'] ?: null;
        $branding = array_filter($branding, fn ($v) => $v !== null);

        $salon->update([
            'branding' => $branding === [] ? null : $branding,
        ]);

        return $salon;
    }
}

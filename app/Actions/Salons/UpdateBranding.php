<?php

namespace App\Actions\Salons;

use App\Models\Salon;

/**
 * Update a salon's display name + accent override (salon settings → branding).
 * The accent feeds the per-salon brandable accent token.
 */
class UpdateBranding
{
    /**
     * @param  array{name: string, accent: string|null}  $data
     */
    public function handle(Salon $salon, array $data): Salon
    {
        $branding = $salon->branding ?? [];
        $branding['accent'] = $data['accent'] ?: null;
        $branding = array_filter($branding, fn ($v) => $v !== null);

        $salon->update([
            'name' => $data['name'],
            'branding' => $branding === [] ? null : $branding,
        ]);

        return $salon;
    }
}

<?php

namespace App\Actions\Services;

use App\Models\Salon;
use App\Models\Service;
use App\Support\ServicePalette;

/**
 * Create a service for a salon. Authorisation (SalonPolicy::manageServices) is
 * enforced by the caller; values are validated there.
 *
 * The service colour is auto-assigned from the curated palette — distinct from
 * (and well-spaced against) the salon's other active services — and stored as a
 * stable key. No manual colour picker; existing services are never reshuffled.
 */
class CreateService
{
    /**
     * @param  array{name: string, duration_min: int, active?: bool}  $data
     */
    public function handle(Salon $salon, array $data): Service
    {
        return $salon->services()->create([
            'name' => $data['name'],
            'duration_min' => $data['duration_min'],
            'color_key' => $this->assignColorKey($salon),
            'active' => $data['active'] ?? true,
        ]);
    }

    /**
     * Pick the next distinct palette key for this salon, counting only its
     * active services (tenant-scoped; another salon never interferes).
     */
    private function assignColorKey(Salon $salon): string
    {
        $counts = $salon->services()
            ->where('active', true)
            ->whereNotNull('color_key')
            ->selectRaw('color_key, count(*) as c')
            ->groupBy('color_key')
            ->pluck('c', 'color_key')
            ->map(fn ($c): int => (int) $c)
            ->all();

        return ServicePalette::pick($counts);
    }
}

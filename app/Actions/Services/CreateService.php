<?php

namespace App\Actions\Services;

use App\Jobs\SyncGhlCalendarSlotSettings;
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
     * @param  array{name: string, duration_min: int, price_cents?: int|null, active?: bool}  $data
     */
    public function handle(Salon $salon, array $data): Service
    {
        $service = $salon->services()->create([
            'name' => $data['name'],
            'duration_min' => $data['duration_min'],
            'price_cents' => $data['price_cents'] ?? null,
            'color_key' => $this->assignColorKey($salon),
            'active' => $data['active'] ?? true,
            // New services join the END of the owner's menu order (never the
            // top): max+1 beats every explicitly ordered row, and beats the
            // legacy default 0 too.
            'sort_order' => ((int) $salon->services()->max('sort_order')) + 1,
        ]);

        // Durations shape GHL's slot settings — mirror the master calendar.
        SyncGhlCalendarSlotSettings::queueFor($salon);

        return $service;
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

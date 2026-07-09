<?php

namespace App\Actions\Services;

use App\Jobs\SyncGhlCalendarSlotSettings;
use App\Models\Salon;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Update a service. The service must belong to the active salon (anti-IDOR).
 */
class UpdateService
{
    /**
     * Colour is auto-assigned at creation and stable, so it is not editable here.
     *
     * @param  array{name: string, duration_min: int, active?: bool}  $data
     */
    public function handle(Salon $salon, Service $service, array $data): Service
    {
        if ($service->salon_id !== $salon->id) {
            throw new AuthorizationException('That service is not in this salon.');
        }

        $service->update([
            'name' => $data['name'],
            'duration_min' => $data['duration_min'],
            'active' => $data['active'] ?? $service->active,
        ]);

        // Durations shape GHL's slot settings — mirror the master calendar.
        SyncGhlCalendarSlotSettings::queueFor($salon);

        return $service;
    }
}

<?php

namespace App\Actions\Services;

use App\Models\Salon;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Update a service. The service must belong to the active salon (anti-IDOR).
 */
class UpdateService
{
    /**
     * @param  array{name: string, duration_min: int, color: string, active?: bool}  $data
     */
    public function handle(Salon $salon, Service $service, array $data): Service
    {
        if ($service->salon_id !== $salon->id) {
            throw new AuthorizationException('That service is not in this salon.');
        }

        $service->update([
            'name' => $data['name'],
            'duration_min' => $data['duration_min'],
            'color' => $data['color'],
            'active' => $data['active'] ?? $service->active,
        ]);

        return $service;
    }
}

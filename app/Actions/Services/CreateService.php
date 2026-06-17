<?php

namespace App\Actions\Services;

use App\Models\Salon;
use App\Models\Service;

/**
 * Create a service for a salon. Authorisation (SalonPolicy::manageServices) is
 * enforced by the caller; values are validated there.
 */
class CreateService
{
    /**
     * @param  array{name: string, duration_min: int, color: string, active?: bool}  $data
     */
    public function handle(Salon $salon, array $data): Service
    {
        return $salon->services()->create([
            'name' => $data['name'],
            'duration_min' => $data['duration_min'],
            'color' => $data['color'],
            'active' => $data['active'] ?? true,
        ]);
    }
}

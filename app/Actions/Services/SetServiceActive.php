<?php

namespace App\Actions\Services;

use App\Models\Salon;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Deactivate/reactivate a service (no hard delete).
 */
class SetServiceActive
{
    public function handle(Salon $salon, Service $service, bool $active): Service
    {
        if ($service->salon_id !== $salon->id) {
            throw new AuthorizationException('That service is not in this salon.');
        }

        $service->update(['active' => $active]);

        return $service;
    }
}

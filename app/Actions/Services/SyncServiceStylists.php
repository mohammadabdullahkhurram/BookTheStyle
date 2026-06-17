<?php

namespace App\Actions\Services;

use App\Models\Salon;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Set which stylists perform a service. Only active stylist members of the
 * service's salon are accepted — any other user id is dropped, so a stylist
 * from another salon can never be assigned (cross-tenant safe).
 */
class SyncServiceStylists
{
    /**
     * @param  array<int, int|string>  $stylistUserIds
     */
    public function handle(Salon $salon, Service $service, array $stylistUserIds): Service
    {
        if ($service->salon_id !== $salon->id) {
            throw new AuthorizationException('That service is not in this salon.');
        }

        $valid = $salon->stylistUsers()
            ->whereKey($stylistUserIds)
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // Always stamp the pivot's salon_id with the service's salon.
        $service->stylists()->sync(
            collect($valid)->mapWithKeys(fn (int $id) => [$id => ['salon_id' => $salon->id]])->all(),
        );

        return $service;
    }
}

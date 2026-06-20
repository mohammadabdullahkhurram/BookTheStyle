<?php

namespace App\Actions\Services;

use App\Models\Salon;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Set which stylists perform a service, with optional per-stylist duration +
 * buffer overrides. Only active stylist members of the service's salon are
 * accepted — any other user id is dropped, so a stylist from another salon can
 * never be assigned (cross-tenant safe).
 *
 * Accepts either a plain list of stylist ids (overrides null) or a map
 * `id => ['duration_override' => ?int, 'buffer_override' => ?int]`.
 */
class SyncServiceStylists
{
    /**
     * @param  array<int|string, int|string|array{duration_override?: int|string|null, buffer_override?: int|string|null}>  $stylists
     */
    public function handle(Salon $salon, Service $service, array $stylists): Service
    {
        if ($service->salon_id !== $salon->id) {
            throw new AuthorizationException('That service is not in this salon.');
        }

        $overrides = $this->normalise($stylists);

        $validIds = $salon->stylistUsers()
            ->whereKey(array_keys($overrides))
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $sync = [];
        foreach ($validIds as $id) {
            $o = $overrides[$id] ?? [];
            $sync[$id] = [
                'salon_id' => $salon->id,
                'duration_override' => $this->minutesOrNull($o['duration_override'] ?? null),
                'buffer_override' => $this->minutesOrNull($o['buffer_override'] ?? null),
            ];
        }

        $service->stylists()->sync($sync);

        return $service;
    }

    /**
     * @param  array<int|string, mixed>  $stylists
     * @return array<int, array{duration_override?: int|string|null, buffer_override?: int|string|null}>
     */
    private function normalise(array $stylists): array
    {
        $out = [];

        foreach ($stylists as $key => $value) {
            if (is_array($value)) {
                $out[(int) $key] = $value;   // map: id => overrides
            } else {
                $out[(int) $value] = [];     // plain list of ids
            }
        }

        return $out;
    }

    private function minutesOrNull(int|string|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}

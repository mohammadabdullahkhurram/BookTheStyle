<?php

namespace App\Services\Booking;

use App\Models\Salon;
use App\Models\Service;
use App\Models\ServiceStylist;

/**
 * The single source of truth for how long a (service, stylist) takes. Resolves
 * the per-stylist override against the service default; the engine, booking
 * flow, and calendar all go through this — nothing reads service.duration_min
 * directly.
 *
 *   service_minutes = duration_override ?? service.duration
 *   buffer_minutes  = buffer_override ?? 0   (and 0 unless the salon's
 *                     stylist_buffers feature flag is on)
 *
 * Buffers stay hidden/disabled until a salon opts in via the flag; when off, the
 * engine treats buffer as 0 regardless of any stored override.
 */
class DurationResolver
{
    public const BUFFER_FLAG = 'stylist_buffers';

    public function resolve(Salon $salon, Service $service, int $stylistId): ResolvedDuration
    {
        $pivot = ServiceStylist::query()
            ->where('service_id', $service->id)
            ->where('user_id', $stylistId)
            ->first();

        return $this->from(
            $service->duration_min,
            $pivot?->duration_override,
            $salon->hasFeature(self::BUFFER_FLAG) ? $pivot?->buffer_override : null,
        );
    }

    /**
     * Pure resolution from raw values (override wins; null → default / 0).
     */
    public function from(int $serviceDefaultMinutes, ?int $durationOverride, ?int $bufferOverride): ResolvedDuration
    {
        return new ResolvedDuration(
            serviceMinutes: $durationOverride ?? $serviceDefaultMinutes,
            bufferMinutes: $bufferOverride ?? 0,
        );
    }
}

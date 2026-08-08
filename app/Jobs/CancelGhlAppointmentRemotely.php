<?php

namespace App\Jobs;

use App\Models\Salon;
use App\Services\Ghl\GhlClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cancel one appointment on the GHL side by its GHL id — used when a synced
 * booking is PERMANENTLY DELETED locally (SyncBookingToGhl cannot help
 * there: it reloads the booking row, which is gone). Carries everything it
 * needs, so it survives the local delete; best-effort with retries — a GHL
 * hiccup never blocks or resurrects the local deletion.
 */
class CancelGhlAppointmentRemotely implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(
        public int $salonId,
        public string $ghlAppointmentId,
    ) {}

    /** @return list<int> seconds before each retry */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $connection = Salon::query()->find($this->salonId)?->ghlConnection()->first();

        if ($connection === null || ! $connection->isConnected()) {
            return;
        }

        GhlClient::fromConnection($connection)->updateAppointment($this->ghlAppointmentId, [
            'appointmentStatus' => 'cancelled',
        ]);
    }
}

<?php

namespace App\Actions\Clients;

use App\Actions\Bookings\PurgeBookings;
use App\Models\Client;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * PERMANENTLY delete a client and every appointment they ever had here —
 * record and history gone (the UI's confirm modal shows the appointment
 * count first). Gated to the salon owner + agency owner/admin
 * (SalonPolicy::hardDelete), salon-scoped, never in the demo. FK-safe
 * order: notes, then each booking's dependents, then the client row.
 * Synced upcoming appointments are cancelled on the GHL side; the GHL
 * CONTACT is deliberately left alone — it belongs to the salon's CRM.
 */
class DeleteClient
{
    public function __construct(private PurgeBookings $purge) {}

    public function handle(User $actor, Salon $salon, Client $client): void
    {
        if ($client->salon_id !== $salon->id) {
            throw new AuthorizationException('That client is not in this salon.');
        }

        if ($salon->is_demo || ! $actor->can('hardDelete', $salon)) {
            throw new AuthorizationException('You may not permanently delete clients here.');
        }

        DB::transaction(function () use ($salon, $client): void {
            $this->purge->handle($salon, $client->bookings()->with('items')->get());
            $client->notes()->delete();
            $client->delete();
        });
    }
}

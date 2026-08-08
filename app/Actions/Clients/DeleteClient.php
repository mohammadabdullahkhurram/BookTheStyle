<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * SOLO delete of a client: the client is removed — their APPOINTMENTS ARE
 * KEPT, every one of them. FK integrity via tombstone: the row soft-deletes
 * (the kept appointments' name snapshot; SoftDeletes hides it from the
 * directory, pickers and counts automatically) with contact details
 * scrubbed; their notes go too — notes are ABOUT the client, not
 * appointments. Gated to the salon owner + agency owner/admin
 * (SalonPolicy::hardDelete), salon-scoped, never in the demo. Nothing is
 * pushed to GHL — no appointment changed.
 */
class DeleteClient
{
    public function handle(User $actor, Salon $salon, Client $client): void
    {
        if ($client->salon_id !== $salon->id) {
            throw new AuthorizationException('That client is not in this salon.');
        }

        if ($salon->is_demo || ! $actor->can('hardDelete', $salon)) {
            throw new AuthorizationException('You may not delete clients here.');
        }

        DB::transaction(function () use ($client): void {
            $client->notes()->delete();
            $client->forceFill(['phone' => null, 'email' => null])->save();
            $client->delete(); // soft — the name stays for kept appointments
        });
    }
}

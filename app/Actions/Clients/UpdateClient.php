<?php

namespace App\Actions\Clients;

use App\Jobs\SyncClientToGhl;
use App\Models\Client;
use App\Models\Salon;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Update a client. The client must belong to the active salon (anti-IDOR).
 * A change to the basic shared fields (name/phone/email) queues a GHL
 * contact push — the app-only profile fields never sync.
 */
class UpdateClient
{
    /**
     * @param  array{name: string, phone?: string|null, email?: string|null}  $data
     */
    public function handle(Salon $salon, Client $client, array $data): Client
    {
        if ($client->salon_id !== $salon->id) {
            throw new AuthorizationException('That client is not in this salon.');
        }

        $client->update([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ]);

        // Mirror basic contact edits to GHL in the background (only when
        // something actually changed and the salon is connected).
        if ($client->wasChanged(['name', 'phone', 'email']) && $salon->ghlConnected()) {
            SyncClientToGhl::queueFor($client);
        }

        return $client;
    }
}

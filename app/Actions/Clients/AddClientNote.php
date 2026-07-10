<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Add a timestamped staff note to a client. Any salon member who can reach
 * bookings may add notes (SalonPolicy::accessBookings — stylists included;
 * they are the ones who know "prefers cooler tones"). The client must belong
 * to the active salon (anti-IDOR).
 */
class AddClientNote
{
    public function handle(User $author, Salon $salon, Client $client, string $body): ClientNote
    {
        if ($client->salon_id !== $salon->id) {
            throw new AuthorizationException('That client is not in this salon.');
        }

        if (! $author->can('accessBookings', $salon)) {
            throw new AuthorizationException('You may not add notes in this salon.');
        }

        return $client->notes()->create([
            'salon_id' => $salon->id,
            'author_id' => $author->id,
            'body' => $body,
        ]);
    }
}

<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\Salon;

/**
 * Create a client for a salon. Used by the clients screen and the booking flow
 * (create-or-select). Authorisation is enforced by the caller.
 *
 * @phpstan-type ClientInput array{name: string, phone?: string|null, email?: string|null}
 */
class CreateClient
{
    /**
     * @param  ClientInput  $data
     */
    public function handle(Salon $salon, array $data): Client
    {
        return $salon->clients()->create([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ]);
    }
}

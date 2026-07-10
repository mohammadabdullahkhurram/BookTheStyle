<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\Salon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Update a client's profile preferences (allergies, formula notes, preferred
 * stylist/contact, birthday). The client must belong to the active salon
 * (anti-IDOR), and a preferred stylist must be one of the salon's own
 * stylists. Authorisation (SalonPolicy::manageBookings) is enforced by the
 * caller; values are validated there.
 */
class UpdateClientPreferences
{
    /**
     * @param  array{allergies?: string|null, formula_notes?: string|null, preferred_stylist_id?: int|null, preferred_contact_method?: string|null, birthday?: string|null}  $data
     */
    public function handle(Salon $salon, Client $client, array $data): Client
    {
        if ($client->salon_id !== $salon->id) {
            throw new AuthorizationException('That client is not in this salon.');
        }

        $stylistId = $data['preferred_stylist_id'] ?? null;

        if ($stylistId !== null && ! $salon->stylistUsers()->whereKey($stylistId)->exists()) {
            throw ValidationException::withMessages([
                'preferred_stylist_id' => __('That person is not an active stylist in this salon.'),
            ]);
        }

        $client->update([
            'allergies' => $data['allergies'] ?? null,
            'formula_notes' => $data['formula_notes'] ?? null,
            'preferred_stylist_id' => $stylistId,
            'preferred_contact_method' => $data['preferred_contact_method'] ?? null,
            'birthday' => $data['birthday'] ?? null,
        ]);

        return $client;
    }
}

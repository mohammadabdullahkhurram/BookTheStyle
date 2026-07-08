<?php

namespace App\Actions\Salons;

use App\Models\Salon;

/**
 * Remove a salon's GoHighLevel connection: the encrypted token, location and
 * master calendar ids, and verification state all go. Stylist ↔ GHL user
 * mappings are deliberately kept — they hold no secret, are only used while
 * connected, and survive a token rotation to the same sub-account.
 * Authorisation (SalonPolicy::manageGhlConnection) is enforced by the caller.
 */
class DisconnectGhl
{
    public function handle(Salon $salon): void
    {
        $salon->ghlConnection()->delete();
        $salon->unsetRelation('ghlConnection');
    }
}

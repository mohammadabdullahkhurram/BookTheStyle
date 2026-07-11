<?php

namespace App\Actions\Salons;

use App\Models\Salon;
use App\Support\BookingApiToken;

/**
 * Issue (or rotate) a salon's Booking API token. Only the sha256 hash is
 * stored; the returned plaintext is shown once and never again. Regenerating
 * immediately invalidates the previous token. Authorisation
 * (SalonPolicy::manage) is enforced by the caller.
 */
class GenerateBookingApiToken
{
    public function handle(Salon $salon): string
    {
        return BookingApiToken::generate($salon);
    }
}

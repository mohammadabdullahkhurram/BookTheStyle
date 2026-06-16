<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Generates the cryptographically-random, single-use temporary passwords issued
 * to new staff and on admin resets. The plaintext is shown once to the admin
 * and emailed; only the Argon2id hash is stored, and must_change_password forces
 * a change on first login.
 */
class TemporaryPassword
{
    public static function generate(): string
    {
        // Str::password() draws from random_int (CSPRNG).
        return Str::password(16, letters: true, numbers: true, symbols: true);
    }
}

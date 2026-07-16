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
        // Str::password() draws from random_int (CSPRNG). Letters + numbers
        // only: symbols like * _ ` are markdown-significant and get eaten by
        // the mail renderer (paired * became <em> and vanished from the
        // email), and they mis-copy across email clients. 20 alphanumeric
        // chars (~119 bits) exceeds the old 16-with-symbols entropy anyway.
        return Str::password(20, letters: true, numbers: true, symbols: false);
    }
}

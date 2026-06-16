<?php

namespace App\Support;

use App\Models\User;

/**
 * Result of provisioning/inviting a user. `temporaryPassword` is the plaintext
 * shown once to the admin (and emailed); it is null when an existing user was
 * simply added to a salon (no new credentials issued).
 */
final readonly class ProvisionedUser
{
    public function __construct(
        public User $user,
        public ?string $temporaryPassword,
        public bool $existing = false,
    ) {}
}

<?php

namespace App\Services\Ghl;

/**
 * A GoHighLevel location user (team member) as returned by GET /users/
 * (UserSchema in GHL's published OpenAPI spec), reduced to what the stylist
 * mapping needs.
 */
final readonly class GhlUser
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = trim(trim((string) ($data['firstName'] ?? '')).' '.trim((string) ($data['lastName'] ?? '')));
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            name: $name,
            email: (string) ($data['email'] ?? ''),
        );
    }
}

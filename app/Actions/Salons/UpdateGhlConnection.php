<?php

namespace App\Actions\Salons;

use App\Models\Salon;
use App\Models\SalonGhlConnection;

/**
 * Create or update a salon's GoHighLevel connection credentials. Authorisation
 * (SalonPolicy::manageGhlConnection) is enforced by the caller.
 *
 * The Private Integration Token is write-only: a blank token input leaves the
 * stored token unchanged (so the masked UI never has to echo it back), and the
 * first time a token is saved we stamp `connected_at`. The token is set
 * explicitly here (never mass-assigned) and is encrypted at rest by the model.
 *
 * This is Phase-6 groundwork: it only stores the credentials. No GHL API call,
 * validation, or sync happens here.
 *
 * @phpstan-type GhlConnectionInput array{
 *     location_id?: string|null,
 *     calendar_id?: string|null,
 *     private_integration_token?: string|null
 * }
 */
class UpdateGhlConnection
{
    /**
     * @param  GhlConnectionInput  $data
     */
    public function handle(Salon $salon, array $data): SalonGhlConnection
    {
        // A demo salon is structurally incapable of reaching GHL.
        if ($salon->is_demo) {
            throw new \RuntimeException('Demo salons cannot connect to GoHighLevel.');
        }

        // Query the relation (not the possibly-cached dynamic property) so a
        // second call on the same instance updates the existing row.
        $connection = $salon->ghlConnection()->first() ?? $salon->ghlConnection()->make();

        // Location + calendar are plain identifiers; empty string clears them.
        $connection->location_id = $this->nullableTrim($data['location_id'] ?? null);
        $connection->calendar_id = $this->nullableTrim($data['calendar_id'] ?? null);

        // Token: blank input → keep the existing token untouched. A non-blank
        // input replaces it, and stamps connected_at the first time one is set.
        $newToken = trim((string) ($data['private_integration_token'] ?? ''));
        if ($newToken !== '') {
            $firstToken = ! $connection->hasToken();
            $connection->private_integration_token = $newToken;

            if ($firstToken && $connection->connected_at === null) {
                $connection->connected_at = now();
            }
        }

        $salon->ghlConnection()->save($connection);

        // Keep the in-memory salon consistent for callers that read the relation.
        $salon->setRelation('ghlConnection', $connection);

        return $connection;
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

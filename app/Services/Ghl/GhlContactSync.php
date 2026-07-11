<?php

namespace App\Services\Ghl;

use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;

/**
 * Bidirectional client↔GHL-contact sync for the BASIC shared fields only —
 * name, phone, email. The app-only profile (notes, allergies, formula,
 * preferences, visit history) NEVER syncs.
 *
 * Loop + conflict handling mirrors the booking sync:
 * - Outbound records a hash of exactly what was pushed (ghl_pushed_hash);
 *   pushing an unchanged state is a no-op (no API call).
 * - Inbound is echo-gated (incoming equals current app state → echo) and
 *   last-edit-wins (payload contact timestamp older than the client's own
 *   updated_at → stale). A timestamp-less payload falls back to hash
 *   comparison: equal to current or to the last push → echo.
 * - Applying an inbound change writes the new state's hash too, and nothing
 *   in the apply path queues an outbound push — the loop cannot start.
 *
 * Inbound CREATION is tag-gated: GHL contacts are a firehose (leads,
 * form-fills, imports), so an unknown contact only becomes an app client if
 * it carries the configured client tag (config ghl.client_tag) — or it
 * already matches an existing client by contact id / phone / email, in
 * which case it is an UPDATE and always applies regardless of tags.
 */
class GhlContactSync
{
    public const STATUS_SYNCED = 'synced';

    public const STATUS_FAILED = 'failed';

    /** The hash of a client's basic shared fields (normalized). */
    public static function basicHash(?string $name, ?string $phone, ?string $email): string
    {
        return hash('sha256', json_encode([
            trim((string) $name),
            trim((string) $phone),
            mb_strtolower(trim((string) $email)),
        ]) ?: '');
    }

    /**
     * Ensure the configured client tag is on this client's GHL contact —
     * exactly once (ghl_client_tagged_at gates repeats), via the ADD-tags
     * endpoint (merge; never overwrites existing tags). Called only from
     * REAL-client moments: a booking push, or an app-side client push. A
     * tag failure never fails the caller — it logs and retries on the next
     * qualifying event.
     */
    public function ensureClientTag(GhlClient $ghl, Client $client): void
    {
        $tag = trim((string) config('ghl.client_tag'));

        if ($tag === '' || $client->ghl_client_tagged_at !== null || $client->ghl_contact_id === null) {
            return;
        }

        try {
            $ghl->addContactTags($client->ghl_contact_id, [$tag]);
        } catch (GhlApiException $e) {
            Log::info('GHL client tag failed — will retry on the next push', [
                'salon_id' => $client->salon_id,
                'client_id' => $client->id,
                'status' => $e->getCode(),
            ]);

            return; // not stamped — retried on the next booking push / edit
        }

        self::markTagged($client);
    }

    /**
     * The tag came from a booking arriving THROUGH GHL: if the inbound
     * payload already shows it, just record that; otherwise add it via the
     * API (a booking makes this contact a real client).
     *
     * @param  list<string>  $payloadTags
     */
    public function ensureClientTagFromInbound(SalonGhlConnection $connection, Client $client, array $payloadTags): void
    {
        if ($client->ghl_client_tagged_at !== null || $client->ghl_contact_id === null) {
            return;
        }

        $tag = mb_strtolower(trim((string) config('ghl.client_tag')));

        if ($tag !== '' && in_array($tag, array_map('mb_strtolower', $payloadTags), true)) {
            self::markTagged($client); // already tagged in GHL — no API call

            return;
        }

        $this->ensureClientTag(GhlClient::fromConnection($connection), $client);
    }

    /** The tag is confirmed present on the GHL contact. */
    public static function markTagged(Client $client): void
    {
        $client->forceFill(['ghl_client_tagged_at' => now()])->save();
    }

    /** Record what was just pushed (or adopted) so its echo is recognized. */
    public static function recordPushed(Client $client, ?string $contactId = null): void
    {
        $client->forceFill([
            ...($contactId !== null ? ['ghl_contact_id' => $contactId] : []),
            'ghl_pushed_hash' => self::basicHash($client->name, $client->phone, $client->email),
            'ghl_pushed_at' => now(),
            'ghl_sync_status' => self::STATUS_SYNCED,
            'ghl_sync_error' => null,
        ])->save();
    }

    /**
     * App → GHL: push the client's current basic fields to its linked
     * contact (creating + linking one when missing). Unchanged state is a
     * no-op. GhlApiException bubbles up so the queued job can retry.
     */
    public function push(Client $client): void
    {
        $connection = $client->salon?->ghlConnection()->first();

        if ($connection === null) {
            return; // salon not connected — nothing to sync, not an error
        }

        if ($client->ghl_contact_id !== null
            && $client->ghl_pushed_hash === self::basicHash($client->name, $client->phone, $client->email)) {
            return; // already in sync — no API call
        }

        $ghl = GhlClient::fromConnection($connection);
        $fields = $this->contactFields($client);

        try {
            if ($client->ghl_contact_id !== null) {
                $ghl->updateContact($client->ghl_contact_id, $fields);
                self::recordPushed($client);
            } else {
                $contact = $ghl->upsertContact($fields);
                $id = $contact['id'] ?? null;

                if (! is_string($id) || $id === '') {
                    throw GhlApiException::fromStatus(500);
                }

                self::recordPushed($client, $id);
            }
        } catch (GhlApiException $e) {
            $client->forceFill([
                'ghl_sync_status' => self::STATUS_FAILED,
                'ghl_sync_error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            throw $e;
        }

        // An app-pushed client IS a real client — make sure the tag is on.
        $this->ensureClientTag($ghl, $client);

        Log::info('GHL contact push applied', [
            'salon_id' => $client->salon_id,
            'client_id' => $client->id,
        ]);
    }

    /**
     * GHL → App: apply an inbound contact create/update webhook. The salon
     * was already resolved from the connection's location id (tenant-safe);
     * only that salon's clients are ever touched.
     */
    public function applyInbound(WebhookEvent $event, Salon $salon, GhlWebhookPayload $payload): void
    {
        if ($payload->contactId === null) {
            $event->conclude(WebhookEvent::STATUS_REVIEW, __('No contact id in the payload.'));

            return;
        }

        $client = $this->matchClient($salon, $payload);

        $decision = function (string $outcome, string $reason) use ($event, $salon, $client): void {
            Log::info('GHL inbound contact decision', [
                'webhook_event_id' => $event->id,
                'salon_id' => $salon->id,
                'client_id' => $client?->id,
                'decision' => $outcome,
                'reason' => $reason,
            ]);
        };

        if ($client === null) {
            $tag = mb_strtolower((string) config('ghl.client_tag'));
            $tagged = $tag !== '' && in_array($tag, array_map('mb_strtolower', $payload->tags), true);

            if (! $tagged || $payload->contactName === null) {
                $decision('ignored_untagged', 'unknown contact without the client tag');
                $event->conclude(WebhookEvent::STATUS_IGNORED_UNTAGGED, __('Contact is not tagged as a client.'));

                return;
            }

            $created = $salon->clients()->create([
                'name' => $payload->contactName,
                'phone' => $payload->contactPhone,
                'email' => $payload->contactEmail,
                'ghl_contact_id' => $payload->contactId,
            ]);
            self::recordPushed($created); // state came FROM GHL — in sync by definition
            self::markTagged($created);   // it qualified BY the tag — already on the contact

            $decision('created_client', 'tagged contact with no matching client');
            $event->conclude(WebhookEvent::STATUS_CREATED_CLIENT, __('Created client from tagged contact.'));

            return;
        }

        // Adopt the contact id when we matched by phone/email.
        if ($client->ghl_contact_id === null) {
            $client->forceFill(['ghl_contact_id' => $payload->contactId])->save();
        }

        // Bookkeeping: the payload shows our client tag is already on the
        // contact — remember it so no push ever re-adds it.
        $tag = mb_strtolower(trim((string) config('ghl.client_tag')));
        if ($client->ghl_client_tagged_at === null && $tag !== ''
            && in_array($tag, array_map('mb_strtolower', $payload->tags), true)) {
            self::markTagged($client);
        }

        // Merge: a field absent from the payload keeps the app value.
        $target = [
            'name' => $payload->contactName ?? $client->name,
            'phone' => $payload->contactPhone ?? $client->phone,
            'email' => $payload->contactEmail ?? $client->email,
        ];
        $incomingHash = self::basicHash($target['name'], $target['phone'], $target['email']);
        $currentHash = self::basicHash($client->name, $client->phone, $client->email);

        // State-equality echo: the incoming state IS the app state.
        if ($incomingHash === $currentHash) {
            $decision('ignored_echo', 'incoming state equals current app state');
            $event->conclude(WebhookEvent::STATUS_IGNORED_ECHO, __('Matches the app state — our own change echoed back.'));

            return;
        }

        // Last-edit-wins: a timestamped change older than the app's own last
        // edit is stale. (updated_at moves on every app-side client edit.)
        if ($payload->contactChangedAt !== null
            && $client->updated_at !== null
            && $payload->contactChangedAt->lte($client->updated_at)) {
            $decision('ignored_stale', 'incoming change older than the app state');
            $event->conclude(WebhookEvent::STATUS_IGNORED_STALE, __('Older than the app\'s last change.'));

            return;
        }

        // Timestamp-less fallback: equal to what we LAST pushed → the echo of
        // an old push arriving late.
        if ($payload->contactChangedAt === null && $incomingHash === $client->ghl_pushed_hash) {
            $decision('ignored_echo', 'timestamp-less state equals the last push');
            $event->conclude(WebhookEvent::STATUS_IGNORED_ECHO, __('Equals the state we last pushed.'));

            return;
        }

        // Apply. Recording the new hash marks the state as in-sync, and this
        // path queues NO outbound push — the loop cannot continue.
        $client->forceFill($target)->save();
        self::recordPushed($client);

        $decision('applied', 'newer GHL change applied to the client');
        $event->conclude(WebhookEvent::STATUS_APPLIED, __('Contact update applied to the client.'));
    }

    /** Match by contact id first, then exact phone, then email. */
    private function matchClient(Salon $salon, GhlWebhookPayload $payload): ?Client
    {
        $client = $salon->clients()->where('ghl_contact_id', $payload->contactId)->first();

        if ($client === null && $payload->contactPhone !== null) {
            $client = $salon->clients()->where('phone', $payload->contactPhone)->first();
        }

        if ($client === null && $payload->contactEmail !== null) {
            $client = $salon->clients()->whereRaw('lower(email) = ?', [mb_strtolower($payload->contactEmail)])->first();
        }

        return $client;
    }

    /**
     * The outbound contact body — the basic shared fields only. The name is
     * split for GHL's first/last model; app-only profile fields never leave.
     *
     * @return array<string, string>
     */
    private function contactFields(Client $client): array
    {
        $parts = preg_split('/\s+/', trim($client->name), 2) ?: [];

        $fields = [
            'firstName' => $parts[0] ?? trim($client->name),
            'lastName' => $parts[1] ?? '',
            'name' => trim($client->name),
        ];

        if (filled($client->email)) {
            $fields['email'] = (string) $client->email;
        }
        if (filled($client->phone)) {
            $fields['phone'] = (string) $client->phone;
        }

        return $fields;
    }
}

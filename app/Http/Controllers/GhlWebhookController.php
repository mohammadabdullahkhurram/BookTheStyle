<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGhlWebhook;
use App\Models\SalonGhlConnection;
use App\Models\WebhookEvent;
use App\Services\Ghl\GhlWebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/ghl — the inbound half of the two-way sync (SPEC §7.3).
 * Central, sessionless, CSRF-exempt; the salon is resolved from the
 * payload's GHL location id and the call is authenticated by the per-salon
 * shared secret the GHL workflow sends in the X-Webhook-Secret header
 * (workflow webhooks carry no platform signature, so the secret IS the
 * authenticity check — hash_equals, per-salon, encrypted at rest).
 *
 * The endpoint acks fast: it verifies, logs the event (replays deduped by
 * body hash), queues the heavy processing, and returns. Nothing here can
 * crash on a malformed body, and nothing sensitive is logged.
 */
class GhlWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if ($payload === []) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        $parsed = GhlWebhookPayload::fromArray($payload);

        // No location → unverifiable; unknown location, missing secret, or a
        // mismatch → one uniform 401 (no information leak either way).
        $connection = $parsed->locationId === null
            ? null
            : SalonGhlConnection::query()->where('location_id', $parsed->locationId)->first();

        $secret = (string) $request->header('X-Webhook-Secret');

        if ($connection === null
            || blank($connection->webhook_secret)
            || ! hash_equals((string) $connection->webhook_secret, $secret)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $hash = hash('sha256', $request->getContent());

        // Replays (identical body for the same salon) are logged and dropped.
        $replay = WebhookEvent::query()
            ->where('salon_id', $connection->salon_id)
            ->where('payload_hash', $hash)
            ->exists();

        $event = WebhookEvent::create([
            'salon_id' => $connection->salon_id,
            'event_type' => is_string($payload['type'] ?? null) ? $payload['type'] : 'appointment',
            'payload' => $payload,
            'payload_hash' => $hash,
            'status' => $replay ? WebhookEvent::STATUS_IGNORED_REPLAY : WebhookEvent::STATUS_PENDING,
            'processed_at' => $replay ? now() : null,
        ]);

        if (! $replay) {
            ProcessGhlWebhook::dispatch($event->id)->afterCommit();
        }

        return response()->json(['received' => true], 202);
    }
}

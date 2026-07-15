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

        // Self-test ping (Settings → Integrations "Test delivery"): it went
        // through the exact verification above, so answering here proves
        // public reachability + the secret without recording a webhook event.
        if (($payload['type'] ?? null) === 'bookthestyle.webhook.test') {
            return response()->json(['received' => true, 'test' => true]);
        }

        $hash = hash('sha256', $request->getContent());

        // Replay protection: GHL workflow bodies carry no nonce/timestamp, so
        // the same cancel delivered twice is byte-identical. Only drop a body
        // whose twin was SUCCESSFULLY processed recently — events that ended
        // pending/review/error (or were themselves dropped) must never block
        // reprocessing, or one bad run deadlocks that appointment forever.
        // Re-processing an old echo later is harmless: state-equality
        // concludes ignored_echo again.
        $replay = WebhookEvent::query()
            ->where('salon_id', $connection->salon_id)
            ->where('payload_hash', $hash)
            ->whereIn('status', [
                WebhookEvent::STATUS_APPLIED,
                WebhookEvent::STATUS_CREATED_BOOKING,
                WebhookEvent::STATUS_IGNORED_ECHO,
                WebhookEvent::STATUS_IGNORED_STALE,
            ])
            ->where('created_at', '>=', now()->subHour())
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

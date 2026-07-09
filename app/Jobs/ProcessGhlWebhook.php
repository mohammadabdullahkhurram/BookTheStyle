<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Ghl\GhlApiException;
use App\Services\Ghl\GhlInboundSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued processing of one verified inbound GHL webhook event. The endpoint
 * already acked; this applies the change via GhlInboundSync. GHL API
 * failures (e.g. cancelling sibling appointments) retry with backoff;
 * unexpected payload shapes are flagged for review — logged without
 * tokens or personal data, never crashing and never retrying forever.
 */
class ProcessGhlWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $webhookEventId) {}

    /**
     * @return list<int> seconds before each retry
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(GhlInboundSync $sync): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null || $event->status !== WebhookEvent::STATUS_PENDING) {
            return;
        }

        try {
            $sync->handle($event);
        } catch (GhlApiException $e) {
            throw $e; // transient API failure — let the queue retry
        } catch (Throwable $e) {
            Log::warning('GHL webhook event needs review', [
                'webhook_event_id' => $event->id,
                'salon_id' => $event->salon_id,
                'exception' => $e::class,
            ]);

            $event->conclude(WebhookEvent::STATUS_REVIEW, $e->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        WebhookEvent::query()->whereKey($this->webhookEventId)->update([
            'status' => WebhookEvent::STATUS_ERROR,
            'note' => mb_substr($exception?->getMessage() ?? 'Unknown error.', 0, 500),
            'processed_at' => now(),
        ]);
    }
}

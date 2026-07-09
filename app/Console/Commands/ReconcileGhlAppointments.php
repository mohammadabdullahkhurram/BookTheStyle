<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\SalonGhlConnection;
use App\Models\WebhookEvent;
use App\Services\Ghl\GhlApiException;
use App\Services\Ghl\GhlClient;
use App\Services\Ghl\GhlInboundSync;
use App\Services\Ghl\GhlStatusMap;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile app bookings with GoHighLevel — the safety net for missed
 * webhooks (downtime, changed webhook URL, GHL hiccups). Per connected
 * salon, the salon's master calendar events over ±N days are pulled (one
 * throttled API call) and compared with the app by ghl_appointment_id:
 *
 * - Matching state → counted as checked; nothing is written and no
 *   WebhookEvent row is created, so hourly runs stay cheap and idempotent.
 * - Drifted state → replayed through GhlInboundSync as a synthetic
 *   'ghl.reconcile' WebhookEvent, so echo suppression, last-change-wins,
 *   and the timestamp-less stale gates all apply exactly as for a real
 *   webhook. Events feed rows DO carry dateUpdated, so conflicts resolve
 *   on real timestamps.
 * - Unknown appointment → becomes an app booking via the same inbound
 *   import path (reverse stylist mapping, client resolution, source
 *   derivation), with the contact fetched once to fill in real details.
 * - App booking whose GHL appointment VANISHED from the window → flagged
 *   as a sync issue (visible in Settings → Integrations) without touching
 *   the booking itself. Only non-terminal bookings that sit fully inside
 *   the window and weren't updated in the last 10 minutes qualify — an
 *   in-flight push must not be misread as a deletion.
 *
 * Run on demand (`php artisan ghl:reconcile {salon?} {--days=7}`) or let the
 * hourly schedule drive it (routes/console.php; in production the Phase-7
 * crontab line `* * * * * php artisan schedule:run` is all it needs).
 */
class ReconcileGhlAppointments extends Command
{
    protected $signature = 'ghl:reconcile {salon? : Limit to one salon id} {--days=7 : Window half-width in days (past and future)}';

    protected $description = 'Pull recent GHL appointments per salon and repair any drift the webhooks missed';

    public function handle(GhlInboundSync $sync): int
    {
        $days = max(1, (int) $this->option('days'));

        $connections = SalonGhlConnection::query()
            ->with('salon')
            ->when($this->argument('salon') !== null, fn ($q) => $q->where('salon_id', (int) $this->argument('salon')))
            ->get()
            ->filter(fn (SalonGhlConnection $connection): bool => $connection->isConnected() && $connection->salon !== null);

        foreach ($connections as $connection) {
            $this->reconcileSalon($sync, $connection, $days);
        }

        return self::SUCCESS;
    }

    private function reconcileSalon(GhlInboundSync $sync, SalonGhlConnection $connection, int $days): void
    {
        $salon = $connection->salon;
        $from = CarbonImmutable::now()->subDays($days);
        $to = CarbonImmutable::now()->addDays($days);

        try {
            $client = GhlClient::fromConnection($connection);
            $events = $client->calendarEvents((string) $connection->calendar_id, $from, $to);
        } catch (GhlApiException $e) {
            // Token-free message; the next run retries this salon.
            $this->error("{$salon->name}: {$e->getMessage()}");
            Log::warning('GHL reconcile: fetch failed', ['salon_id' => $salon->id, 'error' => $e->getMessage()]);

            return;
        }

        $counts = ['checked' => 0, 'updated' => 0, 'created' => 0, 'flagged' => 0, 'review' => 0];
        $seenIds = [];

        foreach ($events as $event) {
            $appointmentId = $event['id'] ?? null;

            // Block slots and malformed rows carry no appointment status.
            if (! is_string($appointmentId) || $appointmentId === '' || blank($event['appointmentStatus'] ?? null)) {
                continue;
            }

            $seenIds[] = $appointmentId;

            $booking = Booking::query()
                ->where('salon_id', $salon->id)
                ->where('ghl_appointment_id', $appointmentId)
                ->first();

            // Cheap pre-filter: a booking already in the event's exact state
            // needs no synthetic webhook (the common case every run).
            if ($booking !== null && $this->matchesBooking($booking, $event)) {
                $counts['checked']++;

                continue;
            }

            $outcome = $this->replayThroughInbound($sync, $connection, $client, $event, isNew: $booking === null);

            match ($outcome) {
                WebhookEvent::STATUS_APPLIED => $counts['updated']++,
                WebhookEvent::STATUS_CREATED_BOOKING => $counts['created']++,
                WebhookEvent::STATUS_REVIEW, WebhookEvent::STATUS_ERROR => $counts['review']++,
                default => $counts['checked']++, // echo / stale — nothing to change
            };
        }

        $counts['flagged'] = $this->flagVanished($salon->id, $seenIds, $from, $to);

        Log::info('GHL reconcile summary', ['salon_id' => $salon->id, ...$counts]);
        $this->info(sprintf(
            '%s: checked %d, updated %d, created %d, flagged %d, review %d.',
            $salon->name, $counts['checked'], $counts['updated'], $counts['created'], $counts['flagged'], $counts['review'],
        ));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function matchesBooking(Booking $booking, array $event): bool
    {
        $incomingStatus = is_string($event['appointmentStatus'] ?? null)
            ? mb_strtolower(trim($event['appointmentStatus']))
            : null;

        if ($incomingStatus !== GhlStatusMap::toGhl($booking->status)) {
            return false;
        }

        $start = $this->time($event['startTime'] ?? null);
        $end = $this->time($event['endTime'] ?? null);
        $currentStart = $booking->items()->min('starts_at');
        $currentEnd = $booking->items()->max('ends_at');

        $startMatches = $start === null || ($currentStart !== null && $start->getTimestamp() === CarbonImmutable::parse($currentStart)->getTimestamp());
        $endMatches = $end === null || ($currentEnd !== null && $end->getTimestamp() === CarbonImmutable::parse($currentEnd)->getTimestamp());

        return $startMatches && $endMatches;
    }

    /**
     * Feed one drifted/unknown GHL event through the SAME inbound pipeline a
     * webhook uses, as an auditable synthetic WebhookEvent. Returns the
     * concluded status.
     *
     * @param  array<string, mixed>  $event
     */
    private function replayThroughInbound(GhlInboundSync $sync, SalonGhlConnection $connection, GhlClient $client, array $event, bool $isNew): string
    {
        $contact = ['id' => $event['contactId'] ?? null];

        // Only an import needs contact details (name/email/phone dedupe);
        // best-effort — a failed lookup still imports a placeholder client.
        if ($isNew && is_string($event['contactId'] ?? null) && $event['contactId'] !== '') {
            try {
                $fetched = $client->contact($event['contactId']);
                $contact = [
                    'id' => $event['contactId'],
                    'name' => $fetched['name'] ?? trim(($fetched['firstName'] ?? '').' '.($fetched['lastName'] ?? '')),
                    'email' => $fetched['email'] ?? null,
                    'phone' => $fetched['phone'] ?? null,
                    'tags' => $fetched['tags'] ?? [],
                ];
            } catch (GhlApiException) {
                // keep the bare id
            }
        }

        $payload = [
            'locationId' => $connection->location_id,
            'appointment' => [
                'id' => $event['id'],
                'calendarId' => $event['calendarId'] ?? null,
                'assignedUserId' => $event['assignedUserId'] ?? null,
                'appointmentStatus' => $event['appointmentStatus'] ?? null,
                'startTime' => $this->time($event['startTime'] ?? null)?->toIso8601String(),
                'endTime' => $this->time($event['endTime'] ?? null)?->toIso8601String(),
                'dateUpdated' => $this->time($event['dateUpdated'] ?? null)?->toIso8601String(),
                'title' => $event['title'] ?? null,
                'createdBy' => $event['createdBy'] ?? null,
            ],
            'contact' => $contact,
        ];

        $webhookEvent = WebhookEvent::create([
            'salon_id' => $connection->salon_id,
            'event_type' => 'ghl.reconcile',
            'payload' => $payload,
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
            'status' => WebhookEvent::STATUS_PENDING,
        ]);

        $sync->handle($webhookEvent);

        return (string) $webhookEvent->fresh()?->status;
    }

    /**
     * Flag mirrored, non-terminal bookings fully inside the window whose
     * appointment no longer exists in GHL. A soft flag only: the booking is
     * untouched, the sync-issues panel surfaces it, and updated_at is never
     * bumped. Re-runs rewrite the same values — idempotent.
     *
     * @param  list<string>  $seenIds
     */
    private function flagVanished(int $salonId, array $seenIds, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $vanished = Booking::query()
            ->where('salon_id', $salonId)
            ->whereNotNull('ghl_appointment_id')
            ->whereNotIn('ghl_appointment_id', $seenIds)
            ->whereIn('status', [BookingStatus::Booked->value, BookingStatus::Confirmed->value, BookingStatus::Arrived->value])
            ->whereHas('items')
            ->whereDoesntHave('items', fn ($q) => $q
                ->where('starts_at', '<', $from->utc())
                ->orWhere('ends_at', '>', $to->utc()))
            // An in-flight push (created moments ago) is not a deletion.
            ->where('updated_at', '<', now()->subMinutes(10))
            ->pluck('id');

        if ($vanished->isEmpty()) {
            return 0;
        }

        Booking::query()->whereIn('id', $vanished)->toBase()->update([
            'ghl_sync_status' => 'failed',
            'ghl_sync_error' => __('The linked GoHighLevel appointment no longer exists.'),
        ]);

        Log::warning('GHL reconcile: appointments vanished from GHL', [
            'salon_id' => $salonId,
            'booking_ids' => $vanished->all(),
        ]);

        return $vanished->count();
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        // The events feed nominally sends ISO strings; guard millis anyway.
        if (is_int($value) || (is_string($value) && ctype_digit($value) && strlen($value) >= 12)) {
            return CarbonImmutable::createFromTimestampMs((int) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

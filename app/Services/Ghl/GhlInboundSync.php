<?php

namespace App\Services\Ghl;

use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\Service;
use App\Models\StylistProfile;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Apply one verified inbound GHL webhook event — the other half of the
 * two-way sync. Everything is scoped to the event's already-resolved salon;
 * a webhook for salon A can never touch salon B.
 *
 * ECHO SUPPRESSION (the critical part): an appointment id the app already
 * knows is compared against the app's CURRENT state for that booking
 * (start, end, and the GHL-mapped status). If the incoming values equal the
 * current state, the event is the echo of our own outbound push (or a
 * duplicate) and is IGNORED — no state flip, no re-push, no loop. Only a
 * genuinely different state proceeds to conflict resolution.
 *
 * LAST-CHANGE-WINS: the payload's change timestamp (appointment.dateUpdated,
 * falling back to receipt time) is compared with the booking's updated_at.
 * Older-than-app inbound changes lose: the app re-pushes its state to
 * correct GHL. Newer inbound changes are applied: reschedules shift the
 * booking's items to the new start (single-item bookings also take a new
 * duration), status changes map through GhlStatusMap. A booking is one
 * stylist (multi-stylist visits are separate bookings sharing a
 * visit_group_id), so a GHL cancel of one appointment cancels exactly that
 * stylist's booking and nothing else. Near-simultaneous edits resolve by
 * whichever side is processed last.
 *
 * Applying an inbound change updates models directly (never through the
 * booking actions), so nothing dispatches an outbound push — and the
 * booking's payload hash is refreshed to the new state so a later push diff
 * sees "unchanged".
 *
 * Appointments the app has never seen become new app bookings (voice AI,
 * chat widget, manual GHL): provider → stylist via the reverse mapping,
 * contact → client by ghl_contact_id / email / phone or created, service
 * best-effort by title match with an inactive "Imported from GoHighLevel"
 * fallback. Anything unplaceable is flagged for review — never dropped.
 */
class GhlInboundSync
{
    public const IMPORT_SERVICE_NAME = 'Imported from GoHighLevel';

    public function handle(WebhookEvent $event): void
    {
        $salon = $event->salon;
        $connection = $salon?->ghlConnection()->first();

        if ($salon === null || $connection === null) {
            $event->conclude(WebhookEvent::STATUS_ERROR, __('The salon connection is gone.'));

            return;
        }

        $payload = GhlWebhookPayload::fromArray($event->payload);

        if ($payload->appointmentId === null) {
            $event->conclude(WebhookEvent::STATUS_REVIEW, __('No appointment id in the payload.'));

            return;
        }

        $booking = Booking::query()
            ->where('salon_id', $salon->id)
            ->where('ghl_appointment_id', $payload->appointmentId)
            ->first();

        // Fallback: an appointment id we never stored can still belong to a
        // local booking — match by contact + exact start instant (only among
        // bookings with no GHL id, so another appointment's booking can never
        // be hijacked) and adopt the id.
        if ($booking === null) {
            $booking = $this->matchByContactAndTime($salon, $payload);

            if ($booking !== null) {
                $booking->forceFill(['ghl_appointment_id' => $payload->appointmentId])->save();
            }
        }

        if ($booking !== null) {
            $this->applyToKnownAppointment($event, $salon, $connection, $payload, $booking);

            return;
        }

        Log::info('GHL inbound: no matching booking', [
            'webhook_event_id' => $event->id,
            'salon_id' => $salon->id,
            'appointment_id' => $payload->appointmentId,
        ]);

        $this->createBookingFromGhl($event, $salon, $connection, $payload);
    }

    private function applyToKnownAppointment(
        WebhookEvent $event,
        Salon $salon,
        SalonGhlConnection $connection,
        GhlWebhookPayload $payload,
        Booking $booking,
    ): void {
        $items = $booking->items()
            ->orderBy('starts_at')
            ->get();

        // Current app state for this booking, in GHL terms.
        $currentStart = $items->min('starts_at');
        $currentEnd = $items->max('ends_at');
        $currentGhlStatus = GhlStatusMap::toGhl($booking->status);

        $startMatches = $payload->startsAt === null
            || ($currentStart !== null && $payload->startsAt->getTimestamp() === $currentStart->getTimestamp());
        $endMatches = $payload->endsAt === null
            || ($currentEnd !== null && $payload->endsAt->getTimestamp() === $currentEnd->getTimestamp());
        $statusMatches = $payload->ghlStatus === null
            || mb_strtolower(trim($payload->ghlStatus)) === $currentGhlStatus;

        // ECHO: the incoming state IS our state — our own push bouncing back
        // (or a duplicate). Ignore: no change, no re-push, no loop.
        if ($startMatches && $endMatches && $statusMatches) {
            $event->conclude(WebhookEvent::STATUS_IGNORED_ECHO, __('Matches the app state — our own change echoed back.'));

            return;
        }

        // LAST-CHANGE-WINS: an inbound change older than the app's latest
        // edit loses, and the app re-pushes to straighten GHL out.
        $incomingAt = $payload->changedAt ?? CarbonImmutable::now();

        if ($booking->updated_at !== null && $incomingAt->lt($booking->updated_at)) {
            $booking->forceFill(['ghl_payload_hash' => null])->save(); // force the re-push through the diff
            SyncBookingToGhl::dispatch($booking->id);

            $event->conclude(WebhookEvent::STATUS_IGNORED_STALE, __('The app changed more recently — re-pushed the app state.'));

            return;
        }

        $fromStatus = $booking->status;

        // Apply the GHL-originated change. Direct model writes only — the
        // booking actions (which dispatch outbound pushes) are never called.
        if ((! $startMatches || ! $endMatches) && $payload->startsAt !== null && $currentStart !== null && $items->isNotEmpty()) {
            $delta = (int) round($currentStart->diffInSeconds($payload->startsAt, false));

            foreach ($items as $item) {
                $item->update([
                    'starts_at' => $item->starts_at->addSeconds($delta),
                    'ends_at' => $item->ends_at->addSeconds($delta),
                ]);
            }

            // A single-service slice also takes a changed duration.
            if ($items->count() === 1 && $payload->endsAt !== null) {
                $items->first()->update(['ends_at' => $payload->endsAt]);
            }
        }

        $incomingStatus = $payload->ghlStatus === null ? null : GhlStatusMap::toApp($payload->ghlStatus);

        if (! $statusMatches && $incomingStatus !== null && $incomingStatus !== $booking->status) {
            $from = $booking->status;
            $booking->update(['status' => $incomingStatus]);
            $booking->statusEvents()->create([
                'salon_id' => $salon->id,
                'from_status' => $from,
                'to_status' => $incomingStatus,
                'actor_user_id' => null, // GHL-originated
            ]);
        }

        $this->refreshBookingSyncState($booking->fresh(), $connection, $payload);

        Log::info('GHL inbound applied', [
            'webhook_event_id' => $event->id,
            'booking_id' => $booking->id,
            'from_status' => $fromStatus->value,
            'to_status' => ($incomingStatus ?? $fromStatus)->value,
            'rescheduled' => ! $startMatches || ! $endMatches,
        ]);

        $event->conclude(WebhookEvent::STATUS_APPLIED);
    }

    /**
     * Contact + exact-start fallback used when the appointment id is unknown:
     * the client resolves by ghl_contact_id / email / phone, and their
     * un-mirrored booking starting at exactly the payload instant matches.
     */
    private function matchByContactAndTime(Salon $salon, GhlWebhookPayload $payload): ?Booking
    {
        if ($payload->startsAt === null) {
            return null;
        }

        $clientQuery = Client::query()->where('salon_id', $salon->id);
        $client = null;
        if ($payload->contactId !== null) {
            $client = (clone $clientQuery)->where('ghl_contact_id', $payload->contactId)->first();
        }
        if ($client === null && $payload->contactEmail !== null) {
            $client = (clone $clientQuery)->where('email', $payload->contactEmail)->first();
        }
        if ($client === null && $payload->contactPhone !== null) {
            $client = (clone $clientQuery)->where('phone', $payload->contactPhone)->first();
        }
        if ($client === null) {
            return null;
        }

        return Booking::query()
            ->where('salon_id', $salon->id)
            ->where('client_id', $client->id)
            ->whereNull('ghl_appointment_id')
            ->whereHas('items', fn ($q) => $q->where('starts_at', $payload->startsAt->utc()))
            ->orderByDesc('id')
            ->first();
    }

    private function createBookingFromGhl(
        WebhookEvent $event,
        Salon $salon,
        SalonGhlConnection $connection,
        GhlWebhookPayload $payload,
    ): void {
        // A cancellation for something we never tracked needs no booking.
        if ($payload->ghlStatus !== null && GhlStatusMap::toApp($payload->ghlStatus) === BookingStatus::Cancelled) {
            $event->conclude(WebhookEvent::STATUS_IGNORED_ECHO, __('Cancelled appointment the app never tracked.'));

            return;
        }

        if ($payload->startsAt === null) {
            $event->conclude(WebhookEvent::STATUS_REVIEW, __('No start time in the payload.'));

            return;
        }

        $stylistId = $payload->assignedUserId === null ? null : StylistProfile::forSalon($salon)
            ->where('ghl_user_id', $payload->assignedUserId)
            ->value('user_id');

        if ($stylistId === null) {
            $event->conclude(WebhookEvent::STATUS_REVIEW, __('The assigned GHL user is not mapped to a stylist.'));

            return;
        }

        $client = $this->resolveClient($salon, $payload);
        $service = $this->resolveService($salon, $payload);
        $source = $this->source($payload->source);

        $endsAt = $payload->endsAt ?? $payload->startsAt->addMinutes($service->duration_min);

        $booking = $salon->bookings()->create([
            'client_id' => $client->id,
            'status' => ($payload->ghlStatus === null ? null : GhlStatusMap::toApp($payload->ghlStatus)) ?? BookingStatus::Booked,
            'booked_by_type' => match ($source) {
                BookingSource::VoiceAi => BookedByType::VoiceAi,
                BookingSource::ChatWidget => BookedByType::ChatWidget,
                default => BookedByType::SalonAdmin, // a human booking inside GHL
            },
            'booked_by_user_id' => null,
            'source' => $source,
            'is_walkin' => false,
            'notes' => null,
        ]);

        $booking->items()->create([
            'salon_id' => $salon->id,
            'service_id' => $service->id,
            'stylist_id' => $stylistId,
            'starts_at' => $payload->startsAt,
            'ends_at' => $endsAt,
        ]);

        $booking->statusEvents()->create([
            'salon_id' => $salon->id,
            'from_status' => null,
            'to_status' => $booking->status,
            'actor_user_id' => null, // GHL-originated
        ]);

        $booking->forceFill(['ghl_appointment_id' => $payload->appointmentId])->save();

        $this->refreshBookingSyncState($booking, $connection, $payload);

        $event->conclude(WebhookEvent::STATUS_CREATED_BOOKING, __('Created booking #:id.', ['id' => $booking->id]));
    }

    /**
     * After applying an inbound change (or importing a booking), align the
     * booking's hash with the NEW state so echo detection and the outbound
     * diff both read this state as already in GHL.
     */
    private function refreshBookingSyncState(Booking $booking, SalonGhlConnection $connection, GhlWebhookPayload $payload): void
    {
        /** @var Collection<int, BookingItem> $items */
        $items = $booking->items()
            ->with('service:id,name')
            ->orderBy('starts_at')
            ->get();

        $providerId = ($items->isEmpty() ? null : StylistProfile::forSalon($booking->salon)
            ->where('user_id', $items->first()->stylist_id)
            ->value('ghl_user_id')) ?? (string) $payload->assignedUserId;

        $hash = null;
        if ($items->isNotEmpty() && filled($providerId)) {
            $appointment = GhlBookingPusher::appointmentPayload($booking, $items, (string) $providerId, (string) $connection->calendar_id);
            $hash = GhlBookingPusher::payloadHash($appointment, (string) ($booking->client->ghl_contact_id ?? $payload->contactId ?? ''));
        }

        $booking->forceFill([
            'ghl_sync_status' => GhlBookingPusher::STATUS_SYNCED,
            'ghl_sync_error' => null,
            'ghl_payload_hash' => $hash,
            'last_synced_at' => now(),
        ])->save();
    }

    private function resolveClient(Salon $salon, GhlWebhookPayload $payload): Client
    {
        $query = Client::query()->where('salon_id', $salon->id);

        $client = null;
        if ($payload->contactId !== null) {
            $client = (clone $query)->where('ghl_contact_id', $payload->contactId)->first();
        }
        if ($client === null && $payload->contactEmail !== null) {
            $client = (clone $query)->where('email', $payload->contactEmail)->first();
        }
        if ($client === null && $payload->contactPhone !== null) {
            $client = (clone $query)->where('phone', $payload->contactPhone)->first();
        }

        $client ??= Client::create([
            'salon_id' => $salon->id,
            'name' => $payload->contactName ?? __('GoHighLevel client'),
            'email' => $payload->contactEmail,
            'phone' => $payload->contactPhone,
        ]);

        if ($payload->contactId !== null && blank($client->ghl_contact_id)) {
            $client->update(['ghl_contact_id' => $payload->contactId]);
        }

        return $client;
    }

    /**
     * Best-effort service resolution: GHL appointments carry a free-text
     * title, not a service id, so match a salon service by name within the
     * title; otherwise fall back to an inactive "Imported from GoHighLevel"
     * placeholder (never bookable in-app, keeps the item's FK honest).
     */
    private function resolveService(Salon $salon, GhlWebhookPayload $payload): Service
    {
        if ($payload->title !== null) {
            $match = $salon->services()
                ->where('active', true)
                ->get(['id', 'name', 'duration_min'])
                ->filter(fn (Service $service): bool => mb_stripos($payload->title, $service->name) !== false)
                ->sortByDesc(fn (Service $service): int => mb_strlen($service->name))
                ->first();

            if ($match !== null) {
                return $match;
            }
        }

        $minutes = ($payload->startsAt !== null && $payload->endsAt !== null)
            ? max(5, (int) $payload->startsAt->diffInMinutes($payload->endsAt))
            : 60;

        return Service::withoutGlobalScopes()->firstOrCreate(
            ['salon_id' => $salon->id, 'name' => self::IMPORT_SERVICE_NAME],
            ['duration_min' => $minutes, 'color_key' => 'sky', 'active' => false],
        );
    }

    private function source(?string $raw): BookingSource
    {
        return match (mb_strtolower(trim((string) $raw))) {
            'voice_ai', 'voice-ai', 'voice' => BookingSource::VoiceAi,
            'chat_widget', 'chat-widget', 'chat' => BookingSource::ChatWidget,
            default => BookingSource::GhlManual,
        };
    }
}

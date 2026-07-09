<?php

namespace App\Services\Ghl;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingGhlAppointment;
use App\Models\BookingItem;
use App\Models\SalonGhlConnection;
use App\Models\StylistProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Throwable;

/**
 * Push one booking's CURRENT state to GoHighLevel — the state-driven core
 * behind the queued SyncBookingToGhl job. The in-app booking is the source
 * of truth: this runs after the fact and never blocks or fails a booking.
 *
 * A booking's services can be performed by DIFFERENT stylists, so it mirrors
 * as ONE GHL APPOINTMENT PER DISTINCT STYLIST, each on that stylist's mapped
 * provider slot with that stylist's own item times (same-time, back-to-back
 * and different-time layouts all land correctly; without this the second
 * stylist looks free in GHL and voice/chat can double-book them). Multiple
 * services for the SAME stylist stay one appointment spanning their combined
 * time. Every stylist slice tracks its own ghl_appointment_id + sync state
 * on booking_ghl_appointments — the ids 6c's echo-loop dedupe keys on.
 *
 * Re-pushes diff by stylist via a payload hash: unchanged slices are left
 * alone, changed ones update their existing appointment (never duplicate),
 * a newly added stylist gets a fresh appointment, and a removed stylist's
 * appointment is cancelled remotely and their row dropped. Cancelling the
 * booking cancels every slice. Unmapped stylists' slices are skipped and
 * flagged without failing the rest.
 *
 * Times are ISO-8601 with the salon's wall-clock offset (DST-safe); slots
 * are pushed with ignoreFreeSlotValidation — the app's engine already
 * validated them and is authoritative. One slice's API failure is recorded
 * on that slice and rethrown so the job retries; already-synced slices are
 * skipped by hash on the retry.
 */
class GhlBookingPusher
{
    public const STATUS_SYNCED = 'synced';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    /**
     * @throws GhlApiException on API failure (the queued job retries, then
     *                         records the failure per stylist slice)
     */
    public function push(Booking $booking): void
    {
        $salon = $booking->salon;
        $connection = $salon->ghlConnection()->first();
        $rows = $booking->ghlAppointments()->get()->keyBy('stylist_id');

        // Cancelled: cancel every slice that ever reached GHL.
        if ($booking->status === BookingStatus::Cancelled) {
            $this->cancelAll($booking, $connection, $rows);

            return;
        }

        $groups = $this->itemsByStylist($booking);

        if ($connection === null || ! $connection->isConnected()) {
            foreach ($groups as $stylistId => $group) {
                $this->row($booking, $rows, (int) $stylistId)
                    ->forceFill(['sync_status' => self::STATUS_SKIPPED, 'sync_error' => __('GoHighLevel is not connected.')])
                    ->save();
            }

            return;
        }

        if ($groups->isEmpty()) {
            return;
        }

        $client = GhlClient::fromConnection($connection);

        // A stylist removed from the booking loses their GHL appointment.
        foreach ($rows as $stylistId => $row) {
            if ($groups->has($stylistId)) {
                continue;
            }

            if ($row->ghl_appointment_id !== null) {
                $client->updateAppointment($row->ghl_appointment_id, ['appointmentStatus' => 'cancelled']);
            }

            $row->delete();
            $rows->forget($stylistId);
        }

        $providers = StylistProfile::forSalon($salon)
            ->whereIn('user_id', $groups->keys()->all())
            ->pluck('ghl_user_id', 'user_id');

        $contactId = null;
        $firstFailure = null;

        foreach ($groups as $stylistId => $items) {
            $row = $this->row($booking, $rows, (int) $stylistId);
            $providerId = $providers[$stylistId] ?? null;

            if (blank($providerId)) {
                $row->forceFill([
                    'sync_status' => self::STATUS_SKIPPED,
                    'sync_error' => __('The stylist is not mapped to a GoHighLevel calendar provider.'),
                ])->save();

                continue;
            }

            try {
                $contactId ??= $this->ensureContact($client, $booking);

                $this->pushSlice($client, $connection->calendar_id, $booking, $row, (string) $providerId, $items, $contactId);
            } catch (Throwable $e) {
                $row->forceFill([
                    'sync_status' => self::STATUS_FAILED,
                    'sync_error' => mb_substr($e->getMessage(), 0, 500),
                ])->save();

                $firstFailure ??= $e;
            }
        }

        // Let the queue retry; hash-unchanged slices are no-ops next time.
        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    /**
     * Create or update one stylist's appointment, skipping the API entirely
     * when nothing about their slice changed since the last successful push.
     *
     * @param  Collection<int, BookingItem>  $items
     */
    private function pushSlice(
        GhlClient $client,
        string $calendarId,
        Booking $booking,
        BookingGhlAppointment $row,
        string $providerId,
        Collection $items,
        string $contactId,
    ): void {
        $payload = self::slicePayload($booking, $items, $providerId, $calendarId);
        $hash = self::sliceHash($payload, $contactId);

        if ($row->ghl_appointment_id !== null && $row->payload_hash === $hash && $row->sync_status === self::STATUS_SYNCED) {
            return; // unchanged slice — leave the appointment alone
        }

        if ($row->ghl_appointment_id !== null) {
            $client->updateAppointment($row->ghl_appointment_id, $payload);
        } else {
            $appointment = $client->createAppointment([...$payload, 'contactId' => $contactId]);

            $id = $appointment['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $row->ghl_appointment_id = $id;
            }
        }

        $row->forceFill([
            'sync_status' => self::STATUS_SYNCED,
            'sync_error' => null,
            'payload_hash' => $hash,
            'last_synced_at' => now(),
        ])->save();
    }

    /**
     * Booking items grouped per stylist, ordered by each stylist's first
     * item start (stable, deterministic push order).
     *
     * @return SupportCollection<int|string, Collection<int, BookingItem>>
     */
    private function itemsByStylist(Booking $booking): SupportCollection
    {
        return $booking->items()
            ->with('service:id,name')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->groupBy('stylist_id')
            ->toBase();
    }

    /**
     * @param  Collection<int|string, BookingGhlAppointment>  $rows
     */
    private function row(Booking $booking, Collection $rows, int $stylistId): BookingGhlAppointment
    {
        $existing = $rows->get($stylistId);

        if ($existing instanceof BookingGhlAppointment) {
            return $existing;
        }

        $row = BookingGhlAppointment::create([
            'salon_id' => $booking->salon_id,
            'booking_id' => $booking->id,
            'stylist_id' => $stylistId,
        ]);

        $rows->put($stylistId, $row);

        return $row;
    }

    /**
     * @param  Collection<int|string, BookingGhlAppointment>  $rows
     */
    private function cancelAll(Booking $booking, ?SalonGhlConnection $connection, Collection $rows): void
    {
        $pushed = $rows->filter(fn (BookingGhlAppointment $row): bool => $row->ghl_appointment_id !== null);

        if ($pushed->isEmpty() || $connection === null || ! $connection->isConnected()) {
            foreach ($rows as $row) {
                if ($row->ghl_appointment_id === null) {
                    $row->forceFill([
                        'sync_status' => self::STATUS_SKIPPED,
                        'sync_error' => __('Cancelled before it was pushed to GoHighLevel.'),
                    ])->save();
                }
            }

            return;
        }

        $client = GhlClient::fromConnection($connection);

        foreach ($pushed as $row) {
            $client->updateAppointment($row->ghl_appointment_id, ['appointmentStatus' => 'cancelled']);

            $row->forceFill([
                'sync_status' => self::STATUS_SYNCED,
                'sync_error' => null,
                'last_synced_at' => now(),
            ])->save();
        }
    }

    /**
     * The GHL contact id for the booking's client — reused when stored,
     * otherwise upserted (GHL matches by email/phone server-side) and saved
     * so future bookings never create duplicates.
     */
    private function ensureContact(GhlClient $client, Booking $booking): string
    {
        $bookingClient = $booking->client;

        if (filled($bookingClient->ghl_contact_id)) {
            return (string) $bookingClient->ghl_contact_id;
        }

        $payload = ['name' => $bookingClient->name];
        if (filled($bookingClient->email)) {
            $payload['email'] = $bookingClient->email;
        }
        if (filled($bookingClient->phone)) {
            $payload['phone'] = $bookingClient->phone;
        }

        $contact = $client->upsertContact($payload);

        $id = $contact['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw GhlApiException::fromStatus(500);
        }

        $bookingClient->update(['ghl_contact_id' => $id]);

        return $id;
    }

    /**
     * The exact appointment body one stylist's slice pushes to GHL — shared
     * with the inbound sync so an applied GHL change can refresh the slice
     * hash to the new state (keeping echo detection and diffing coherent).
     *
     * @param  Collection<int, BookingItem>  $items
     * @return array<string, mixed>
     */
    public static function slicePayload(Booking $booking, Collection $items, string $providerId, string $calendarId): array
    {
        $tz = $booking->salon->timezone;
        $services = $items->pluck('service.name')->filter()->implode(', ');

        return [
            'title' => trim($booking->client->name.($services !== '' ? ' — '.$services : '')),
            'appointmentStatus' => GhlStatusMap::toGhl($booking->status),
            'assignedUserId' => $providerId,
            'calendarId' => $calendarId,
            'startTime' => $items->min('starts_at')->setTimezone($tz)->format('Y-m-d\TH:i:sP'),
            'endTime' => $items->max('ends_at')->setTimezone($tz)->format('Y-m-d\TH:i:sP'),
            // The app's slot engine already validated this slot and is the
            // source of truth; GHL must not reject it against its own hours.
            'ignoreFreeSlotValidation' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function sliceHash(array $payload, string $contactId): string
    {
        return hash('sha256', json_encode([$payload, $contactId]) ?: '');
    }
}

<?php

namespace App\Services\Ghl;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\StylistProfile;
use Illuminate\Database\Eloquent\Collection;

/**
 * Push one booking's CURRENT state to GoHighLevel — the state-driven core
 * behind the queued SyncBookingToGhl job. The in-app booking is the source
 * of truth: this runs after the fact and never blocks or fails a booking.
 *
 * A booking is ONE SERVICE performed by one stylist (composed visits persist
 * as separate per-service bookings linked by visit_group_id), so the mirror
 * is a clean 1:1 with no grouping of any kind: one booking → one GHL
 * appointment on that stylist's mapped provider, at that booking's own time,
 * titled with that single service. The appointment id, sync status/error,
 * payload hash and last-synced time live directly on the booking.
 *
 * Re-pushes diff by the payload hash: an unchanged booking makes no API
 * call, a changed one updates its existing appointment (never duplicates),
 * and a cancelled booking cancels it (kept in GHL as a cancelled record).
 * Unconnected salons and unmapped stylists skip silently with a flag. Times
 * are ISO-8601 with the salon's wall-clock offset (DST-safe); slots are
 * pushed with ignoreFreeSlotValidation — the app's engine already validated
 * them and is authoritative.
 */
class GhlBookingPusher
{
    public const STATUS_SYNCED = 'synced';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    /**
     * @throws GhlApiException on API failure (the queued job retries, then
     *                         records the failure on the booking)
     */
    public function push(Booking $booking): void
    {
        $salon = $booking->salon;
        $connection = $salon->ghlConnection()->first();

        if ($connection === null || ! $connection->isConnected()) {
            $this->mark($booking, self::STATUS_SKIPPED, __('GoHighLevel is not connected.'));

            return;
        }

        // Cancelled: only reaches GHL if the booking ever did.
        if ($booking->status === BookingStatus::Cancelled) {
            if ($booking->ghl_appointment_id === null) {
                $this->mark($booking, self::STATUS_SKIPPED, __('Cancelled before it was pushed to GoHighLevel.'));

                return;
            }

            GhlClient::fromConnection($connection)->updateAppointment($booking->ghl_appointment_id, [
                'appointmentStatus' => 'cancelled',
            ]);

            $this->mark($booking, self::STATUS_SYNCED, null, touchSyncedAt: true);

            return;
        }

        $items = $booking->items()->with('service:id,name')->orderBy('starts_at')->get();

        if ($items->isEmpty()) {
            $this->mark($booking, self::STATUS_SKIPPED, __('The booking has no service items.'));

            return;
        }

        // One booking = one stylist; their calendar-provider mapping routes it.
        $providerId = StylistProfile::forSalon($salon)
            ->where('user_id', $items->first()->stylist_id)
            ->value('ghl_user_id');

        if (blank($providerId)) {
            $this->mark($booking, self::STATUS_SKIPPED, __('The stylist is not mapped to a GoHighLevel calendar provider.'));

            return;
        }

        $client = GhlClient::fromConnection($connection);
        $contactId = $this->ensureContact($client, $booking);

        $payload = self::appointmentPayload($booking, $items, (string) $providerId, (string) $connection->calendar_id);
        $hash = self::payloadHash($payload, $contactId);

        if ($booking->ghl_appointment_id !== null && $booking->ghl_payload_hash === $hash && $booking->ghl_sync_status === self::STATUS_SYNCED) {
            return; // unchanged — leave the appointment alone
        }

        if ($booking->ghl_appointment_id !== null) {
            $client->updateAppointment($booking->ghl_appointment_id, $payload);
        } else {
            $appointment = $client->createAppointment([...$payload, 'contactId' => $contactId]);

            $id = $appointment['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $booking->ghl_appointment_id = $id;
            }
        }

        $booking->ghl_payload_hash = $hash;
        $this->mark($booking, self::STATUS_SYNCED, null, touchSyncedAt: true);
    }

    /**
     * The exact appointment body this booking pushes: ITS OWN times (first
     * item start → last item client-facing end) and ITS OWN services in the
     * title — never another stylist's. Shared with the inbound sync so an
     * applied GHL change can refresh the hash to the new state.
     *
     * @param  Collection<int, BookingItem>  $items
     * @return array<string, mixed>
     */
    public static function appointmentPayload(Booking $booking, Collection $items, string $providerId, string $calendarId): array
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
    public static function payloadHash(array $payload, string $contactId): string
    {
        return hash('sha256', json_encode([$payload, $contactId]) ?: '');
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

    private function mark(Booking $booking, string $status, ?string $error, bool $touchSyncedAt = false): void
    {
        $booking->forceFill([
            'ghl_sync_status' => $status,
            'ghl_sync_error' => $error,
            ...($touchSyncedAt ? ['last_synced_at' => now()] : []),
        ])->save();
    }
}

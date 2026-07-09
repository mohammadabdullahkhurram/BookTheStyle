<?php

namespace App\Services\Ghl;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\StylistProfile;

/**
 * Push one booking's CURRENT state to GoHighLevel as a calendar appointment —
 * the state-driven core behind the queued SyncBookingToGhl job. The in-app
 * booking is the source of truth: this runs after the fact and never blocks
 * or fails a booking.
 *
 * Flow: skip silently unless the salon has a complete GHL connection AND the
 * booking's primary stylist is mapped to a calendar provider; upsert the
 * client as a GHL contact (reusing a stored ghl_contact_id); then create the
 * appointment — or, when a ghl_appointment_id is already stored, update that
 * same appointment (reschedules never duplicate; that id is also 6c's
 * echo-loop key). A cancelled booking cancels its GHL appointment via
 * appointmentStatus rather than deletion, so the record survives in GHL.
 *
 * Multi-service bookings become ONE appointment spanning the first item's
 * start to the last item's client-facing end, assigned to the FIRST item's
 * stylist (the primary provider). SPEC §7 maps bookings to a single mirrored
 * GHL appointment; per-item pushes can come later if a salon needs them.
 *
 * Times are sent as ISO-8601 with the salon's wall-clock offset (DST-safe),
 * and slots are pushed with ignoreFreeSlotValidation — the app's engine
 * already validated them and is authoritative.
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

        // Cancellation: only reaches GHL if the booking ever did.
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

        // The primary provider: the first item's stylist must be mapped to a
        // calendar team member. Other staff's location-user links are never
        // used for routing.
        $providerId = StylistProfile::forSalon($salon)
            ->where('user_id', $items->first()->stylist_id)
            ->value('ghl_user_id');

        if (blank($providerId)) {
            $this->mark($booking, self::STATUS_SKIPPED, __('The stylist is not mapped to a GoHighLevel calendar provider.'));

            return;
        }

        $client = GhlClient::fromConnection($connection);
        $contactId = $this->ensureContact($client, $booking);

        $tz = $salon->timezone;
        $services = $items->pluck('service.name')->filter()->implode(', ');

        $payload = [
            'title' => trim($booking->client->name.($services !== '' ? ' — '.$services : '')),
            'appointmentStatus' => $this->appointmentStatus($booking->status),
            'assignedUserId' => (string) $providerId,
            'calendarId' => (string) $connection->calendar_id,
            'startTime' => $items->first()->starts_at->setTimezone($tz)->format('Y-m-d\TH:i:sP'),
            'endTime' => $items->last()->ends_at->setTimezone($tz)->format('Y-m-d\TH:i:sP'),
            // The app's slot engine already validated this slot and is the
            // source of truth; GHL must not reject it against its own hours.
            'ignoreFreeSlotValidation' => true,
        ];

        if ($booking->ghl_appointment_id !== null) {
            $client->updateAppointment($booking->ghl_appointment_id, $payload);
        } else {
            $appointment = $client->createAppointment([...$payload, 'contactId' => $contactId]);

            $id = $appointment['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $booking->ghl_appointment_id = $id;
            }
        }

        $this->mark($booking, self::STATUS_SYNCED, null, touchSyncedAt: true);
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

    private function appointmentStatus(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Cancelled => 'cancelled',
            BookingStatus::NoShow => 'noshow',
            BookingStatus::Completed => 'showed',
            default => 'confirmed',
        };
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

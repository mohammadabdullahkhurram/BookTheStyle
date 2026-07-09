<?php

namespace App\Services\Ghl;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Defensive reader for an inbound GHL workflow webhook body. Workflow
 * payload shapes vary between trigger versions, so every field is resolved
 * through a list of known aliases — `appointment.*` (current), `calendar.*`
 * (legacy workflow trigger), then flat root keys, then `customData.*` (our
 * recommended explicit mapping in the workflow action). Anything missing is
 * simply null; the inbound sync decides what is usable.
 */
final readonly class GhlWebhookPayload
{
    public function __construct(
        public ?string $locationId,
        public ?string $appointmentId,
        public ?string $calendarId,
        public ?string $assignedUserId,
        public ?string $ghlStatus,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public ?CarbonImmutable $changedAt,
        public ?string $contactId,
        public ?string $contactName,
        public ?string $contactEmail,
        public ?string $contactPhone,
        public ?string $source,
        public ?string $title,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $pick = function (array $paths) use ($payload): ?string {
            foreach ($paths as $path) {
                $value = data_get($payload, $path);
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
                if (is_int($value) || is_float($value)) {
                    return (string) $value;
                }
            }

            return null;
        };

        // Workflow payloads send LOCAL wall-clock times ("2026-07-27T16:30:00",
        // no offset) qualified by calendar.selectedTimezone — parse offset-less
        // strings in that zone (Carbon ignores the fallback zone whenever the
        // string carries its own offset), DST-safe.
        $fallbackTz = $pick(['calendar.selectedTimezone', 'appointment.selectedTimezone', 'selectedTimezone', 'timezone']);

        $time = function (array $paths) use ($pick, $fallbackTz): ?CarbonImmutable {
            $raw = $pick($paths);
            if ($raw === null) {
                return null;
            }

            try {
                return CarbonImmutable::parse($raw, $fallbackTz);
            } catch (Throwable) {
                return null;
            }
        };

        return new self(
            locationId: $pick(['locationId', 'location.id', 'location_id', 'customData.locationId']),
            // calendar.appointmentId is the appointment; calendar.id is the
            // CALENDAR's id and must never be used as an appointment id.
            appointmentId: $pick(['appointment.id', 'appointmentId', 'calendar.appointmentId', 'customData.appointmentId']),
            calendarId: $pick(['appointment.calendarId', 'calendar.calendarId', 'calendar.id', 'calendarId', 'customData.calendarId']),
            assignedUserId: $pick(['appointment.assignedUserId', 'calendar.assignedUserId', 'assignedUserId', 'user.id', 'customData.assignedUserId']),
            // The LIVE status is "appoinmentStatus" (GHL's misspelling, one t);
            // calendar.status ("booked") is a different, stale field — it is
            // only a last-resort fallback.
            ghlStatus: $pick([
                'calendar.appoinmentStatus', 'calendar.appointmentStatus',
                'appointment.appoinmentStatus', 'appointment.appointmentStatus',
                'appoinmentStatus', 'appointmentStatus', 'customData.appointmentStatus',
                'appointment.status', 'calendar.status', 'status',
            ]),
            startsAt: $time(['appointment.startTime', 'calendar.startTime', 'startTime', 'customData.startTime']),
            endsAt: $time(['appointment.endTime', 'calendar.endTime', 'endTime', 'customData.endTime']),
            changedAt: $time(['appointment.dateUpdated', 'appointment.dateAdded', 'calendar.dateUpdated', 'dateUpdated', 'timestamp', 'customData.dateUpdated']),
            contactId: $pick(['appointment.contactId', 'contact.id', 'contact_id', 'contactId', 'customData.contactId']),
            contactName: $pick(['contact.name', 'full_name', 'contact.fullName', 'customData.contactName']),
            contactEmail: $pick(['contact.email', 'email', 'customData.contactEmail']),
            contactPhone: $pick(['contact.phone', 'phone', 'customData.contactPhone']),
            source: $pick(['customData.source', 'appointment.source', 'source']),
            title: $pick(['appointment.title', 'calendar.title', 'title', 'customData.title']),
        );
    }
}

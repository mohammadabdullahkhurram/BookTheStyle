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

        $time = function (array $paths) use ($pick): ?CarbonImmutable {
            $raw = $pick($paths);
            if ($raw === null) {
                return null;
            }

            try {
                return CarbonImmutable::parse($raw);
            } catch (Throwable) {
                return null;
            }
        };

        return new self(
            locationId: $pick(['locationId', 'location.id', 'location_id', 'customData.locationId']),
            appointmentId: $pick(['appointment.id', 'appointmentId', 'calendar.appointmentId', 'calendar.id', 'customData.appointmentId']),
            calendarId: $pick(['appointment.calendarId', 'calendar.calendarId', 'calendarId', 'customData.calendarId']),
            assignedUserId: $pick(['appointment.assignedUserId', 'calendar.assignedUserId', 'assignedUserId', 'user.id', 'customData.assignedUserId']),
            ghlStatus: $pick(['appointment.appointmentStatus', 'appointment.status', 'calendar.status', 'appointmentStatus', 'status', 'customData.appointmentStatus']),
            startsAt: $time(['appointment.startTime', 'calendar.startTime', 'startTime', 'customData.startTime']),
            endsAt: $time(['appointment.endTime', 'calendar.endTime', 'endTime', 'customData.endTime']),
            changedAt: $time(['appointment.dateUpdated', 'appointment.dateAdded', 'dateUpdated', 'timestamp', 'customData.dateUpdated']),
            contactId: $pick(['appointment.contactId', 'contact.id', 'contact_id', 'contactId', 'customData.contactId']),
            contactName: $pick(['contact.name', 'full_name', 'contact.fullName', 'customData.contactName']),
            contactEmail: $pick(['contact.email', 'email', 'customData.contactEmail']),
            contactPhone: $pick(['contact.phone', 'phone', 'customData.contactPhone']),
            source: $pick(['customData.source', 'appointment.source', 'source']),
            title: $pick(['appointment.title', 'calendar.title', 'title', 'customData.title']),
        );
    }
}

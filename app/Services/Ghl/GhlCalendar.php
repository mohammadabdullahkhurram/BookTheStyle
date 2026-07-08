<?php

namespace App\Services\Ghl;

/**
 * A GoHighLevel calendar as returned by GET /calendars/ (CalendarDTO in GHL's
 * published OpenAPI spec). Only the fields Phase 6a needs: identity, type,
 * whether it is live, its location, and the team members (GHL user ids) that
 * appointments can be routed to.
 */
final readonly class GhlCalendar
{
    /**
     * @param  list<string>  $teamMemberIds
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $calendarType,
        public bool $isActive,
        public ?string $locationId,
        public array $teamMemberIds,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $members = [];
        foreach ((array) ($data['teamMembers'] ?? []) as $member) {
            if (is_array($member) && is_string($member['userId'] ?? null) && $member['userId'] !== '') {
                $members[] = $member['userId'];
            }
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            calendarType: isset($data['calendarType']) ? (string) $data['calendarType'] : null,
            isActive: (bool) ($data['isActive'] ?? true),
            locationId: isset($data['locationId']) ? (string) $data['locationId'] : null,
            teamMemberIds: $members,
        );
    }
}

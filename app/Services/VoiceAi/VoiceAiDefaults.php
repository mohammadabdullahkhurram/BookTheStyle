<?php

namespace App\Services\VoiceAi;

use App\Enums\AvailabilityKind;
use App\Models\Availability;
use App\Models\Salon;

/**
 * Smart defaults for the Voice AI Prompts form — drafts pulled from what
 * BookTheStyle already knows about the salon: one stylist row per active
 * bookable REAL staff member, a natural spoken address from the stored
 * profile, and spoken opening hours derived from the staff's weekly work
 * windows (the salon's effective open hours: earliest start to latest end
 * per day, across bookable staff). Drafts are ordinary editable values —
 * saved settings always win over these.
 */
class VoiceAiDefaults
{
    /** @return list<array{name: string, specialties: string, days: string}> */
    public function stylists(Salon $salon): array
    {
        $rows = $salon->stylistUsers()
            ->where('users.is_test', false)
            ->orderBy('name')
            ->pluck('users.name')
            ->map(fn ($name): array => ['name' => (string) $name, 'specialties' => '', 'days' => ''])
            ->all();

        return array_values($rows);
    }

    /** "We're at {number + street} in {city}" — no zip/country/unit noise. */
    public function addressSpoken(Salon $salon): string
    {
        $street = trim($salon->address_line1);
        $city = trim($salon->city);

        if ($street === '' && $city === '') {
            return '';
        }

        if ($city === '') {
            return "We're at {$street}";
        }

        if ($street === '') {
            return "We're in {$city}";
        }

        return "We're at {$street} in {$city}";
    }

    /** Spoken weekly hours from the bookable staff's work windows. */
    public function hoursSpoken(Salon $salon): string
    {
        $stylistIds = $salon->stylistUsers()->where('users.is_test', false)->pluck('users.id');

        if ($stylistIds->isEmpty()) {
            return '';
        }

        $rows = Availability::forSalon($salon)
            ->where('kind', AvailabilityKind::Work->value)
            ->whereIn('user_id', $stylistIds)
            ->get(['weekday', 'start_minute', 'end_minute']);

        $week = [];
        foreach ($rows as $row) {
            $day = (int) $row->weekday;
            $week[$day] = [
                'start' => isset($week[$day]) ? min($week[$day]['start'], (int) $row->start_minute) : (int) $row->start_minute,
                'end' => isset($week[$day]) ? max($week[$day]['end'], (int) $row->end_minute) : (int) $row->end_minute,
            ];
        }

        return SpokenHours::phrase($week);
    }
}

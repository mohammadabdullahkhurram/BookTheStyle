<?php

namespace App\Enums;

use App\Models\Salon;
use App\Models\User;

/**
 * The kind of actor who created a booking (SPEC §4). In-app bookings use the
 * four human roles; voice_ai / chat_widget arrive with GHL in Phase 6.
 */
enum BookedByType: string
{
    case SalonOwner = 'salon_owner';
    case SalonAdmin = 'salon_admin';
    case Stylist = 'stylist';
    case FrontDesk = 'front_desk';
    case VoiceAi = 'voice_ai';
    case ChatWidget = 'chat_widget';
    case WebWidget = 'web_widget';

    public function label(): string
    {
        return match ($this) {
            self::SalonOwner => 'Salon Owner',
            self::SalonAdmin => 'Salon Admin',
            self::Stylist => 'Stylist',
            self::FrontDesk => 'Front Desk',
            self::VoiceAi => 'Voice AI',
            self::ChatWidget => 'Chat widget',
            self::WebWidget => 'Booking widget',
        };
    }

    /**
     * Derive the booked-by type from the authenticated actor's role in the
     * salon. Agency operators (no salon membership) are recorded as admin-level.
     */
    public static function fromActor(User $actor, Salon $salon): self
    {
        $membership = $actor->membershipFor($salon);

        if ($membership === null) {
            return self::SalonAdmin;
        }

        // FrontDesk survives as a CASE for historical rows only — the
        // front-desk role/type died in the owner/manager/stylist rework.
        return match ($membership->salon_role) {
            SalonRole::Owner => self::SalonOwner,
            SalonRole::Manager => self::SalonAdmin,
            SalonRole::Stylist => self::Stylist,
        };
    }
}

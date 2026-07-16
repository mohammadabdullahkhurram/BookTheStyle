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

        return match (true) {
            $membership->salon_role === SalonRole::Owner => self::SalonOwner,
            // Attribution is FUNCTIONAL: front desk holds the admin role
            // since the remap, but "who booked it" still reads Front desk.
            $membership->staff_type === StaffType::FrontDesk => self::FrontDesk,
            $membership->salon_role === SalonRole::Admin => self::SalonAdmin,
            default => self::Stylist,
        };
    }
}

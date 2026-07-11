<?php

namespace App\Enums;

/**
 * Where a booking originated (SPEC §4). Everything created in the app is
 * in_app; the GHL-origin sources are derived by GhlSourceResolver from the
 * inbound payload's explicit customData.source, contact tags, and the
 * created_by / last_updated_by metadata. A GHL-originated booking whose
 * channel cannot be determined is ghl_other — never mislabelled as manual.
 */
enum BookingSource: string
{
    case InApp = 'in_app';
    case VoiceAi = 'voice_ai';
    case ChatWidget = 'chat_widget';
    case WebWidget = 'web_widget';
    case GhlManual = 'ghl_manual';
    case GhlOther = 'ghl_other';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'In app',
            self::VoiceAi => 'Voice AI',
            self::ChatWidget => 'Chat widget',
            self::WebWidget => 'Booking widget',
            self::GhlManual => 'GHL (manual)',
            self::GhlOther => 'GHL (other)',
        };
    }
}

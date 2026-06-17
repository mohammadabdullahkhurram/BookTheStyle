<?php

namespace App\Enums;

/**
 * Where a booking originated (SPEC §4). Everything created in the app is
 * in_app; the GHL-origin sources arrive in Phase 6.
 */
enum BookingSource: string
{
    case InApp = 'in_app';
    case VoiceAi = 'voice_ai';
    case ChatWidget = 'chat_widget';
    case GhlManual = 'ghl_manual';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'In app',
            self::VoiceAi => 'Voice AI',
            self::ChatWidget => 'Chat widget',
            self::GhlManual => 'GHL (manual)',
        };
    }
}

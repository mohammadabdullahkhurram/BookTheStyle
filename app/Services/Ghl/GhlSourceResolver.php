<?php

namespace App\Services\Ghl;

use App\Enums\BookingSource;

/**
 * Derive a BookingSource for a GHL-originated appointment, best-signal first:
 *
 * 1. An EXPLICIT source (our recommended customData.source mapping in the
 *    GHL workflow) — authoritative when present.
 * 2. Contact tags — voice/chat automations in GHL conventionally tag the
 *    contact (e.g. "voice-ai booking"), and salons can add such tags freely.
 * 3. The created_by / last_updated_by metadata GHL stamps on the
 *    appointment: "web_app" / calendar-page sources mean a human inside the
 *    GHL UI (ghl_manual); "third_party"/"api"/"workflow" only proves it came
 *    through an integration, not WHICH one.
 *
 * Anything that can't be pinned down is ghl_other — GHL-originated, channel
 * unknown — never guessed into a specific channel.
 */
class GhlSourceResolver
{
    /**
     * @param  list<string>  $tags
     * @param  list<string|null>  $metaHints  created_by source/channel then
     *                                        last_updated_by source/channel
     */
    public static function resolve(?string $explicit, array $tags, array $metaHints): BookingSource
    {
        $fromExplicit = self::fromExplicit($explicit);
        if ($fromExplicit !== null) {
            return $fromExplicit;
        }

        foreach ($tags as $tag) {
            $fromTag = self::fromKeywords($tag);
            if ($fromTag !== null) {
                return $fromTag;
            }
        }

        foreach ($metaHints as $hint) {
            $fromHint = self::fromMeta($hint);
            if ($fromHint !== null) {
                return $fromHint;
            }
        }

        return BookingSource::GhlOther;
    }

    private static function fromExplicit(?string $value): ?BookingSource
    {
        return match (self::normalize($value)) {
            'voice_ai', 'ghl_voice', 'voice' => BookingSource::VoiceAi,
            'chat_widget', 'ghl_chat', 'chat' => BookingSource::ChatWidget,
            'ghl_manual', 'manual' => BookingSource::GhlManual,
            default => null,
        };
    }

    /** Keyword sniff for free-text signals (contact tags). */
    private static function fromKeywords(?string $value): ?BookingSource
    {
        $value = self::normalize($value);

        if ($value === null) {
            return null;
        }

        if (str_contains($value, 'voice')) {
            return BookingSource::VoiceAi;
        }

        if (str_contains($value, 'chat')) {
            return BookingSource::ChatWidget;
        }

        return null;
    }

    /**
     * created_by_meta / last_updated_by_meta source or channel values seen in
     * real payloads: source "third_party" | "appointment_page" |
     * "calendar_page", channel "web_app". web_app / calendar pages = a human
     * driving GHL's own UI; integration-ish values stay unknown (ghl_other).
     */
    private static function fromMeta(?string $value): ?BookingSource
    {
        $value = self::normalize($value);

        if ($value === null) {
            return null;
        }

        if (str_contains($value, 'voice')) {
            return BookingSource::VoiceAi;
        }

        if (str_contains($value, 'chat')) {
            return BookingSource::ChatWidget;
        }

        if (in_array($value, ['web_app', 'calendar_page', 'appointment_page', 'calendar', 'manual'], true)) {
            return BookingSource::GhlManual;
        }

        return null;
    }

    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        return $value === '' ? null : str_replace(['-', ' '], '_', $value);
    }
}

<?php

namespace App\Support;

/**
 * The kinds of embeddable widget a salon can create (Widgets area). Same
 * pattern as ThemeRegistry: a registry of keys with metadata, where only
 * `available` types are creatable and the coming_soon entries render as
 * locked preview cards in the type picker. Adding a future type = add the
 * entry here, flip its status when it ships, and give it a renderer.
 *
 * Only `booking` is real today — the existing booking widget IS this type.
 */
final class WidgetTypeRegistry
{
    public const DEFAULT = 'booking';

    /**
     * @var array<string, array{name: string, description: string, icon: string, status: string}>
     */
    public const TYPES = [
        'booking' => [
            'name' => 'Booking widget',
            'description' => 'The full booking flow on your website — services, stylists, live availability, confirmed appointments.',
            'icon' => 'calendar-days',
            'status' => 'available',
        ],
        'chat' => [
            'name' => 'Chat widget',
            'description' => 'A chat bubble for your site that answers questions and books through the voice-AI brain.',
            'icon' => 'chat-bubble-left-right',
            'status' => 'coming_soon',
        ],
        'lead_form' => [
            'name' => 'Lead form',
            'description' => 'A short branded form that captures name and phone into your client list.',
            'icon' => 'clipboard-document-list',
            'status' => 'coming_soon',
        ],
        'reviews' => [
            'name' => 'Reviews widget',
            'description' => 'Showcase your best client reviews on your own site.',
            'icon' => 'star',
            'status' => 'coming_soon',
        ],
    ];

    /** Whether a type exists and can actually be created today. */
    public static function selectable(?string $key): bool
    {
        return ($type = self::TYPES[$key] ?? null) !== null && $type['status'] === 'available';
    }

    /** The display name for a stored type key (unknown keys read as booking). */
    public static function name(?string $key): string
    {
        return (self::TYPES[$key] ?? self::TYPES[self::DEFAULT])['name'];
    }

    /** The icon for a stored type key. */
    public static function icon(?string $key): string
    {
        return (self::TYPES[$key] ?? self::TYPES[self::DEFAULT])['icon'];
    }
}

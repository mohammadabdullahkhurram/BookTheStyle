<?php

namespace App\Support;

/**
 * The named design themes. A theme is a complete, swappable set of design-
 * token VALUES — the token architecture (CSS custom properties consumed by
 * the Tailwind @theme layer and the .bts-* primitives) never changes; a
 * theme only selects which values are active, via data-theme on <body>
 * (body-scoped so wire:navigate carries it — same mechanism as LumenTheme).
 * Token blocks live in resources/css/app.css under body[data-theme='<key>'].
 *
 * `status` gates selection: only `available` themes can be picked; the
 * coming_soon entries render as locked preview cards in the pickers (no
 * token block needed until they ship). `scopes` says where a theme can be
 * chosen: the salon APP shell, a booking WIDGET, or both. Glacier is
 * deliberately app-only — it is the AGENCY console's language, applied to
 * the agency shell automatically (see AppTheme).
 */
final class ThemeRegistry
{
    public const SCOPE_APP = 'app';

    public const SCOPE_WIDGET = 'widget';

    /** The agency console's language — applied by route, never picker-chosen. */
    public const SCOPE_AGENCY = 'agency';

    /** Renders as the BASE token set: no data-theme attribute at all. */
    public const CLASSIC = 'classic';

    /** The salon app's standard theme going forward. */
    public const DEFAULT_APP = 'marble';

    /**
     * @var array<string, array{name: string, description: string, status: string, scopes: list<string>, swatches: list<string>}>
     */
    public const THEMES = [
        'marble' => [
            'name' => 'Marble',
            'description' => 'Warm and story-book human: butter cream surfaces, coral energy, chunky rounded shapes. The BookTheStyle standard.',
            'status' => 'available',
            'scopes' => [self::SCOPE_APP, self::SCOPE_WIDGET],
            'swatches' => ['#FFF8EF', '#BC4A28', '#F7D774'],
        ],
        'classic' => [
            'name' => 'Classic',
            'description' => 'The original BookTheStyle look — warm paper, plum accent, quiet editorial calm.',
            'status' => 'available',
            'scopes' => [self::SCOPE_APP],
            'swatches' => ['#F6F5F3', '#824C71', '#1C1B1A'],
        ],
        'glacier' => [
            'name' => 'Glacier',
            'description' => 'Full liquid glass over soft colour blooms — the agency console language.',
            'status' => 'available',
            'scopes' => [self::SCOPE_AGENCY],
            'swatches' => ['#F3F1EE', '#824C71', '#5B92BD'],
        ],
        'velvet' => [
            'name' => 'Velvet',
            'description' => 'Moody jewel tones and plush depth.',
            'status' => 'coming_soon',
            'scopes' => [self::SCOPE_APP, self::SCOPE_WIDGET],
            'swatches' => ['#2A2233', '#B08AD9', '#D9B74E'],
        ],
        'gazette' => [
            'name' => 'Gazette',
            'description' => 'Crisp newsprint: hairlines, serifs, ink on cream.',
            'status' => 'coming_soon',
            'scopes' => [self::SCOPE_APP, self::SCOPE_WIDGET],
            'swatches' => ['#FAF6EC', '#1C1B1A', '#A23A3A'],
        ],
        'fern' => [
            'name' => 'Fern',
            'description' => 'Botanical greens and natural warmth.',
            'status' => 'coming_soon',
            'scopes' => [self::SCOPE_APP, self::SCOPE_WIDGET],
            'swatches' => ['#F2F5EE', '#3E5C3A', '#C2A15A'],
        ],
    ];

    /** Whether a theme exists, is available, and may be used in the scope. */
    public static function selectable(?string $key, string $scope): bool
    {
        $theme = self::THEMES[$key] ?? null;

        return $theme !== null
            && $theme['status'] === 'available'
            && in_array($scope, $theme['scopes'], true);
    }

    /**
     * The picker cards for a scope: every theme usable there (available
     * first, then the locked coming-soon previews), keyed by theme key.
     *
     * @return array<string, array{name: string, description: string, status: string, scopes: list<string>, swatches: list<string>}>
     */
    public static function picker(string $scope): array
    {
        $themes = array_filter(
            self::THEMES,
            fn (array $theme): bool => in_array($scope, $theme['scopes'], true),
        );

        uasort($themes, fn (array $a, array $b): int => strcmp($a['status'], $b['status']));

        return $themes;
    }

    /**
     * The data-theme attribute value for a key. Classic (and any unknown or
     * retired key, including the pre-rollout stored value 'default') renders
     * the BASE token set — no attribute — which IS the original look.
     */
    public static function bodyTheme(?string $key): ?string
    {
        return $key !== null && $key !== self::CLASSIC && ($mode = self::THEMES[$key] ?? null) !== null && $mode['status'] === 'available'
            ? $key
            : null;
    }
}

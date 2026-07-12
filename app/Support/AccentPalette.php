<?php

namespace App\Support;

/**
 * Resolves a salon's chosen accent into the four swappable accent tokens
 * (--accent / --accent-hover / --accent-tint / --accent-ink) used across the
 * design system. Violet is the default (set in CSS); a salon may pick one of
 * the named presets (violet / sage / terracotta) or any custom hex, which is
 * applied by overriding those four variables inline (see partials/head).
 *
 * Known preset hexes resolve to their exact DESIGN-TOKENS values; any other
 * valid hex derives hover / tint / ink by mixing toward black/white.
 */
final class AccentPalette
{
    /**
     * @var array<string, array{accent: string, hover: string, tint: string, ink: string}>
     */
    public const PRESETS = [
        // The default "violet" preset is the warm plum of the refreshed
        // visual language (white-on-accent 6.5:1, ink-on-tint 8.0:1).
        'violet' => ['accent' => '#824C71', 'hover' => '#6D3C5E', 'tint' => '#F5EAF0', 'ink' => '#6B3358'],
        'sage' => ['accent' => '#5C7458', 'hover' => '#4F6349', 'tint' => '#E7EEE5', 'ink' => '#3E5C3A'],
        'terracotta' => ['accent' => '#C0613E', 'hover' => '#A8502F', 'tint' => '#F4E6DD', 'ink' => '#8A431F'],
    ];

    /**
     * The four accent tokens for a salon's chosen accent, or null when there is
     * no valid override (so the CSS default violet stands).
     *
     * @return array{accent: string, hover: string, tint: string, ink: string}|null
     */
    public static function resolve(?string $accent): ?array
    {
        if ($accent === null || $accent === '') {
            return null;
        }

        // Named preset.
        $key = strtolower(trim($accent));
        if (isset(self::PRESETS[$key])) {
            return self::PRESETS[$key];
        }

        // Custom hex (#rrggbb). Match a known preset hex exactly, else derive.
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1) {
            return null;
        }

        foreach (self::PRESETS as $preset) {
            if (strcasecmp($preset['accent'], $accent) === 0) {
                return $preset;
            }
        }

        return [
            'accent' => strtoupper($accent),
            'hover' => self::mix($accent, '#000000', 0.14),
            'tint' => self::mix($accent, '#FFFFFF', 0.88),
            'ink' => self::mix($accent, '#000000', 0.30),
        ];
    }

    /**
     * Mix $hex toward $towards by $amount (0..1 of $towards).
     */
    private static function mix(string $hex, string $towards, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($hex);
        [$r2, $g2, $b2] = self::rgb($towards);

        $r = (int) round($r1 + ($r2 - $r1) * $amount);
        $g = (int) round($g1 + ($g2 - $g1) * $amount);
        $b = (int) round($b1 + ($b2 - $b1) * $amount);

        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}

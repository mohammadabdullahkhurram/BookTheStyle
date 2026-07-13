<?php

namespace App\Support;

use App\Models\Salon;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a salon's widget branding (settings → Branding) into the theme
 * the public booking widget renders: the accent set (via AccentPalette),
 * a secondary warmth colour, the surface/backdrop base, the curated font
 * pairing, and the uploaded logo URL. Everything falls back to the app's
 * own defaults, so existing salons render sensibly with no configuration.
 *
 * Stored in the salons.branding JSON (additive keys beside the existing
 * accent): secondary, surface, font, logo_path.
 */
final class WidgetBranding
{
    public const DEFAULT_SECONDARY = '#D68A6F'; // warm clay/blush wash

    public const DEFAULT_SURFACE = '#F7F4EF';   // the app's warm paper

    public const DEFAULT_FONT = 'editorial';

    /**
     * Curated, reliably-loadable pairings: the three self-hosted app faces
     * plus two web-safe classics. Each maps to display + body stacks.
     *
     * @var array<string, array{label: string, display: string, body: string}>
     */
    public const FONTS = [
        'editorial' => [
            'label' => 'Editorial serif — Fraunces',
            'display' => "'Fraunces', 'Schibsted Grotesk', Georgia, serif",
            'body' => "'Hanken Grotesk', ui-sans-serif, system-ui, sans-serif",
        ],
        'modern' => [
            'label' => 'Modern grotesk — Schibsted',
            'display' => "'Schibsted Grotesk', ui-sans-serif, system-ui, sans-serif",
            'body' => "'Hanken Grotesk', ui-sans-serif, system-ui, sans-serif",
        ],
        'soft' => [
            'label' => 'Soft sans — Hanken',
            'display' => "'Hanken Grotesk', ui-sans-serif, system-ui, sans-serif",
            'body' => "'Hanken Grotesk', ui-sans-serif, system-ui, sans-serif",
        ],
        'classic' => [
            'label' => 'Classic serif — Georgia',
            'display' => 'Georgia, "Times New Roman", serif',
            'body' => 'Georgia, "Times New Roman", serif',
        ],
        'neutral' => [
            'label' => 'Neutral — Helvetica',
            'display' => "'Helvetica Neue', Helvetica, Arial, sans-serif",
            'body' => "'Helvetica Neue', Helvetica, Arial, sans-serif",
        ],
    ];

    /**
     * The widget theme for a salon (optionally with a validated ?accent=
     * override, which beats the stored accent — existing behaviour).
     *
     * `mode` is DERIVED from the branded surface so the widget stays readable
     * on whatever background the salon sets: the foreground family (ink /
     * muted / faint / hairlines / raised cells) flips between light-on-dark
     * and dark-on-light by WCAG contrast, and `accent_ink` is the readable
     * text colour ON the accent (button fills, the selected calendar day).
     *
     * @return array{accent: array{accent: string, hover: string, tint: string, ink: string}, secondary: string, surface: string, font: array{key: string, label: string, display: string, body: string}, logo_url: string|null, mode: array{scheme: string, ink: string, muted: string, faint: string, line: string, cell: string, accent_ink: string}}
     */
    public static function for(Salon $salon, ?string $accentOverride = null): array
    {
        $branding = $salon->branding ?? [];

        $accent = AccentPalette::resolve($accentOverride ?? $salon->accentColor())
            ?? AccentPalette::PRESETS['violet'];

        $fontKey = is_string($branding['font'] ?? null) && isset(self::FONTS[$branding['font']])
            ? $branding['font']
            : self::DEFAULT_FONT;

        $logoPath = is_string($branding['logo_path'] ?? null) ? $branding['logo_path'] : null;
        $font = self::FONTS[$fontKey];
        $surface = self::hexOr($branding['surface'] ?? null, self::DEFAULT_SURFACE);

        return [
            'accent' => $accent,
            'secondary' => self::hexOr($branding['secondary'] ?? null, self::DEFAULT_SECONDARY),
            'surface' => $surface,
            'font' => [
                'key' => $fontKey,
                'label' => $font['label'],
                'display' => $font['display'],
                'body' => $font['body'],
            ],
            'logo_url' => $logoPath !== null && Storage::disk('public')->exists($logoPath)
                ? Storage::disk('public')->url($logoPath)
                : null,
            'mode' => self::mode($surface, $accent['accent']),
        ];
    }

    /**
     * @return array{scheme: string, ink: string, muted: string, faint: string, line: string, cell: string, accent_ink: string}
     */
    private static function mode(string $surface, string $accent): array
    {
        $dark = self::contrast($surface, '#FFFFFF') >= self::contrast($surface, '#1C1B1A');

        return [
            'scheme' => $dark ? 'dark' : 'light',
            'ink' => $dark ? '#FFFFFF' : '#1C1B1A',
            'muted' => $dark ? 'rgb(255 255 255 / .74)' : 'rgb(28 27 26 / .68)',
            'faint' => $dark ? 'rgb(255 255 255 / .42)' : 'rgb(28 27 26 / .38)',
            'line' => $dark ? 'rgb(255 255 255 / .16)' : 'rgb(28 27 26 / .12)',
            'cell' => $dark ? 'rgb(255 255 255 / .07)' : 'rgb(255 255 255 / .72)',
            'accent_ink' => self::contrast($accent, '#FFFFFF') >= self::contrast($accent, '#1C1B1A')
                ? '#FFFFFF'
                : '#1C1B1A',
        ];
    }

    /** WCAG contrast ratio between two hex colours (for the settings warning). */
    public static function contrast(string $a, string $b): float
    {
        $l = fn (string $hex): float => self::luminance($hex);
        [$la, $lb] = [$l($a), $l($b)];

        return round(($la >= $lb ? ($la + 0.05) / ($lb + 0.05) : ($lb + 0.05) / ($la + 0.05)), 2);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $lin = array_map(function (int $i) use ($hex): float {
            $c = hexdec(substr($hex, $i * 2, 2)) / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, [0, 1, 2]);

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    private static function hexOr(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtoupper($value)
            : $default;
    }
}

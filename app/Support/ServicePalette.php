<?php

namespace App\Support;

/**
 * Curated palette of soft, on-brand service colours (DESIGN-TOKENS "Service
 * colour palette"). Each entry is a { bg, border, ink } triplet plus a solid
 * `dot` for small swatches, in the same aesthetic as the stylist pastel
 * families but a wider, distinct set. Calendar appointment blocks are coloured
 * BY SERVICE from these; stylists stay distinguishable by their avatar colour
 * (PastelPalette).
 *
 * The list order is hue-spaced so picking sequentially yields visibly distinct
 * neighbours. A service stores its `color_key`; nothing is reshuffled when
 * other services are added or removed.
 */
final class ServicePalette
{
    /**
     * @var list<array{key: string, bg: string, border: string, ink: string, dot: string}>
     */
    public const COLORS = [
        ['key' => 'green', 'bg' => '#E7EFE4', 'border' => '#D5E4D0', 'ink' => '#3E5C3A', 'dot' => '#6E9968'],
        ['key' => 'rose', 'bg' => '#FBE7EE', 'border' => '#F2D2DE', 'ink' => '#8E3D5A', 'dot' => '#C76A8C'],
        ['key' => 'sky', 'bg' => '#E1EDF6', 'border' => '#C8DFEF', 'ink' => '#2F5D7C', 'dot' => '#5B92BD'],
        ['key' => 'amber', 'bg' => '#FBEFD6', 'border' => '#EEDDB6', 'ink' => '#8A5A1E', 'dot' => '#D49A4E'],
        ['key' => 'violet', 'bg' => '#EAE6FB', 'border' => '#D8D1F2', 'ink' => '#4B3F9E', 'dot' => '#8C7FE0'],
        ['key' => 'teal', 'bg' => '#DDEEEA', 'border' => '#C2E0D9', 'ink' => '#2C6E63', 'dot' => '#4E9C8C'],
        ['key' => 'coral', 'bg' => '#FBE5E0', 'border' => '#F3CFC6', 'ink' => '#A24433', 'dot' => '#D87A66'],
        ['key' => 'blue', 'bg' => '#E4E8F7', 'border' => '#CDD4F0', 'ink' => '#3A4A93', 'dot' => '#6E80D6'],
        ['key' => 'peach', 'bg' => '#FBEBDB', 'border' => '#F2D8BF', 'ink' => '#9A5A2A', 'dot' => '#D98E55'],
        ['key' => 'pink', 'bg' => '#FAE6F3', 'border' => '#F0D0E7', 'ink' => '#94407A', 'dot' => '#C56FAC'],
        ['key' => 'sage', 'bg' => '#E8EDE3', 'border' => '#D6DECB', 'ink' => '#4C5E43', 'dot' => '#7E916C'],
        ['key' => 'lavender', 'bg' => '#ECE9F7', 'border' => '#DBD5EE', 'ink' => '#5A4E92', 'dot' => '#9A8DD6'],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::COLORS, 'key');
    }

    public static function count(): int
    {
        return count(self::COLORS);
    }

    /**
     * Resolve a key to its triplet, falling back to the first colour for an
     * unknown/null key (e.g. legacy data before backfill).
     *
     * @return array{key: string, bg: string, border: string, ink: string, dot: string}
     */
    public static function get(?string $key): array
    {
        foreach (self::COLORS as $color) {
            if ($color['key'] === $key) {
                return $color;
            }
        }

        return self::COLORS[0];
    }

    /**
     * Choose the colour key for a new service given how often each key is
     * already used among a salon's (active) services — a pure function so the
     * create action and the backfill migration share one rule.
     *
     * - Prefer a colour not yet used; among unused, the one furthest from the
     *   colours already in use (ties → palette order). This guarantees no
     *   duplicate, and never a near-identical colour while a distinct one is free.
     * - When every colour is used (more services than the palette), reuse the
     *   least-used colour, breaking ties by furthest from the other used colours
     *   — so reuse spreads out instead of clustering similar hues.
     *
     * @param  array<string, int>  $usedCounts  key => number of services using it
     */
    public static function pick(array $usedCounts): string
    {
        $keys = self::keys();

        // No services yet → the first palette colour.
        if ($usedCounts === []) {
            return $keys[0];
        }

        $distinctUsed = array_values(array_intersect($keys, array_keys($usedCounts)));
        $unused = array_values(array_diff($keys, $distinctUsed));

        if ($unused !== []) {
            return self::farthest($unused, $distinctUsed);
        }

        $min = min($usedCounts);
        $leastUsed = array_values(array_intersect(
            $keys,
            array_keys(array_filter($usedCounts, fn (int $c): bool => $c === $min)),
        ));

        return self::farthest($leastUsed, $keys);
    }

    /**
     * The candidate (in palette order) whose colour is furthest from the nearest
     * colour in $set. An empty $set leaves every candidate equidistant, so the
     * first in palette order wins.
     *
     * @param  list<string>  $candidates
     * @param  list<string>  $set
     */
    private static function farthest(array $candidates, array $set): string
    {
        $best = $candidates[0];
        $bestScore = -1.0;

        foreach ($candidates as $key) {
            $score = self::minDistanceToSet($key, $set);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $key;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $set
     */
    private static function minDistanceToSet(string $key, array $set): float
    {
        $min = INF;

        foreach ($set as $other) {
            if ($other === $key) {
                continue;
            }
            $min = min($min, self::distance($key, $other));
        }

        return $min;
    }

    /** Euclidean RGB distance between two keys' solid dot colours. */
    private static function distance(string $a, string $b): float
    {
        [$ar, $ag, $ab] = self::rgb(self::get($a)['dot']);
        [$br, $bg, $bb] = self::rgb(self::get($b)['dot']);

        return sqrt((($ar - $br) ** 2) + (($ag - $bg) ** 2) + (($ab - $bb) ** 2));
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

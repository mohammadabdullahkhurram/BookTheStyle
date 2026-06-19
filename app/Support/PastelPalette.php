<?php

namespace App\Support;

/**
 * The rotating four-colour pastel family used for stylist calendar blocks and
 * client avatars (DESIGN-TOKENS "Stylist / service pastel families"). Assigned
 * in order — index by a stable seed (e.g. the stylist's id) so a given stylist
 * keeps the same colour across the app.
 */
final class PastelPalette
{
    /**
     * @var list<array{name: string, bg: string, border: string, ink: string, avatar: string}>
     */
    public const FAMILIES = [
        ['name' => 'green', 'bg' => '#E7EFE4', 'border' => '#D5E4D0', 'ink' => '#3E5C3A', 'avatar' => '#6E9968'],
        ['name' => 'pink', 'bg' => '#FBE7EE', 'border' => '#F2D2DE', 'ink' => '#8E3D5A', 'avatar' => '#C76A8C'],
        ['name' => 'amber', 'bg' => '#FBEFD6', 'border' => '#EEDDB6', 'ink' => '#8A5A1E', 'avatar' => '#D49A4E'],
        ['name' => 'violet', 'bg' => '#EAE6FB', 'border' => '#D8D1F2', 'ink' => '#4B3F9E', 'avatar' => '#8C7FE0'],
    ];

    /**
     * @return array{name: string, bg: string, border: string, ink: string, avatar: string}
     */
    public static function forSeed(int $seed): array
    {
        $count = count(self::FAMILIES);

        return self::FAMILIES[(($seed % $count) + $count) % $count];
    }
}

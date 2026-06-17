<?php

namespace App\Support;

/**
 * System subdomains that salons may NOT claim as their slug. These belong to
 * central/apex use (marketing, auth, agency console) or reserved system paths
 * such as /cal (Phase 5) and /webhooks (Phase 6). A salon slug becomes a live
 * subdomain ({slug}.bookthestyle.com), so collisions here would shadow the
 * platform itself.
 */
final class ReservedSlugs
{
    /**
     * @var list<string>
     */
    public const LIST = [
        'www',
        'app',
        'api',
        'admin',
        'mail',
        'cal',
        'webhooks',
        'assets',
        'static',
        'help',
        'support',
        'status',
    ];

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::LIST, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::LIST;
    }
}

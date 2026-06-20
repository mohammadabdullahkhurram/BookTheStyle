<?php

namespace App\Support;

/**
 * Resolves how-to documentation entries from config/help.php into HelpDoc value
 * objects. Adding a new doc is a one-line registry entry (plus the video file);
 * unknown keys resolve to null so callers can guard safely.
 */
class HelpDocs
{
    public static function find(string $key): ?HelpDoc
    {
        $docs = config('help.docs');
        $entry = is_array($docs) ? ($docs[$key] ?? null) : null;

        if (! is_array($entry)) {
            return null;
        }

        return new HelpDoc(
            key: $key,
            title: (string) ($entry['title'] ?? $key),
            caption: isset($entry['caption']) ? (string) $entry['caption'] : null,
            video: isset($entry['video']) ? (string) $entry['video'] : null,
            videoWebm: isset($entry['video_webm']) ? (string) $entry['video_webm'] : null,
            poster: isset($entry['poster']) ? (string) $entry['poster'] : null,
        );
    }

    /**
     * @return array<string, HelpDoc>
     */
    public static function all(): array
    {
        $docs = config('help.docs');
        $out = [];

        foreach (array_keys(is_array($docs) ? $docs : []) as $key) {
            $doc = self::find((string) $key);

            if ($doc !== null) {
                $out[(string) $key] = $doc;
            }
        }

        return $out;
    }
}

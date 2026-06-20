<?php

namespace App\Support;

/**
 * A resolved how-to documentation entry (see config/help.php). Knows its title,
 * caption, and media, and checks the public filesystem so a not-yet-uploaded
 * video degrades gracefully (the help modal shows a placeholder instead of a
 * broken player). Media URLs are root-relative, so they stay same-origin on any
 * host (no CSP change).
 */
class HelpDoc
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly ?string $caption = null,
        private readonly ?string $video = null,
        private readonly ?string $videoWebm = null,
        private readonly ?string $poster = null,
    ) {}

    /**
     * Video <source>s for the files that actually exist on disk (mp4, then webm).
     *
     * @return list<array{url: string, type: string}>
     */
    public function videoSources(): array
    {
        $sources = [];

        foreach ([[$this->video, 'video/mp4'], [$this->videoWebm, 'video/webm']] as [$path, $type]) {
            if ($path !== null && is_file(public_path($path))) {
                $sources[] = ['url' => self::asset($path), 'type' => $type];
            }
        }

        return $sources;
    }

    /**
     * Whether any video file is present yet (drives the graceful placeholder).
     */
    public function hasVideo(): bool
    {
        return $this->videoSources() !== [];
    }

    public function posterUrl(): ?string
    {
        return ($this->poster !== null && is_file(public_path($this->poster)))
            ? self::asset($this->poster)
            : null;
    }

    /** Root-relative public URL (same-origin on every host). */
    private static function asset(string $path): string
    {
        return '/'.ltrim($path, '/');
    }
}

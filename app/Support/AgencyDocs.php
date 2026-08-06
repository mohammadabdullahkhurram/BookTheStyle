<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Internal documentation for agency users: technical docs and SOPs authored
 * as markdown FILES in resources/docs (versioned with the repo — adding a
 * doc is committing a file; an in-app editor is a future phase). Each file
 * carries a small front-matter block:
 *
 *   ---
 *   title: Salon onboarding + GHL & Voice AI setup
 *   category: SOPs
 *   order: 1
 *   ---
 *
 * The slug is the filename (kebab-case, no extension). Rendering uses
 * league/commonmark — already in the stack via laravel/framework, so no new
 * dependency — with GFM (tables, task lists, autolinks), anchored heading
 * permalinks, RAW HTML STRIPPED and unsafe links refused: docs are internal
 * but treated as untrusted markup anyway. Images live in public/docs-assets
 * and are referenced from docs as /docs-assets/<file>.
 */
class AgencyDocs
{
    /**
     * The doc index: slug, title, category, order — sorted for the sidebar
     * (category, then explicit order, then title).
     *
     * @return Collection<int, array{slug: string, title: string, category: string, order: int}>
     */
    public function all(): Collection
    {
        $files = glob($this->basePath().'/*.md') ?: [];

        return collect($files)
            ->map(function (string $path): array {
                [$meta] = $this->parse((string) file_get_contents($path));
                $slug = basename($path, '.md');

                return [
                    'slug' => $slug,
                    'title' => $meta['title'] ?? Str::headline($slug),
                    'category' => $meta['category'] ?? 'General',
                    'order' => (int) ($meta['order'] ?? 999),
                ];
            })
            ->sortBy([['category', 'asc'], ['order', 'asc'], ['title', 'asc']])
            ->values();
    }

    /**
     * A single doc by slug, rendered to safe HTML — or null when it does not
     * exist. Slugs are whitelisted to kebab-case, so no path can traverse.
     *
     * @return array{slug: string, title: string, category: string, html: string}|null
     */
    public function find(string $slug): ?array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return null;
        }

        $path = $this->basePath().'/'.$slug.'.md';

        if (! is_file($path)) {
            return null;
        }

        [$meta, $body] = $this->parse((string) file_get_contents($path));

        return [
            'slug' => $slug,
            'title' => $meta['title'] ?? Str::headline($slug),
            'category' => $meta['category'] ?? 'General',
            'html' => $this->render($body),
        ];
    }

    private function basePath(): string
    {
        return resource_path('docs');
    }

    /**
     * Split the optional front-matter block from the body. Deliberately a
     * tiny `key: value` parser — no YAML dependency for three fields.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function parse(string $raw): array
    {
        if (! str_starts_with($raw, "---\n")) {
            return [[], $raw];
        }

        $end = strpos($raw, "\n---", 4);

        if ($end === false) {
            return [[], $raw];
        }

        $meta = [];
        foreach (explode("\n", substr($raw, 4, $end - 4)) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $meta[trim($key)] = trim($value);
            }
        }

        return [$meta, ltrim(substr($raw, $end + 4), "-\n")];
    }

    private function render(string $markdown): string
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'heading_permalink' => [
                'symbol' => '#',
                'insert' => 'after',
                'aria_hidden' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);

        return (new MarkdownConverter($environment))->convert($markdown)->getContent();
    }
}

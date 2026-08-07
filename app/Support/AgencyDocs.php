<?php

namespace App\Support;

/**
 * Internal documentation for agency users — NATIVE Blade pages, not
 * markdown. Each doc is a Blade view under resources/views/docs built from
 * the x-docs.* component kit (sections, steps, callouts, screenshot slots,
 * fill-ins, inline-SVG diagrams). This class is the registry: slugs, titles,
 * the TWO top-level groups (SOPs vs Technical), and each doc's section list
 * for the on-page navigation. Adding a doc = adding a Blade view + one
 * entry here. Access stays AgencyPolicy::viewDocs (every agency role).
 */
class AgencyDocs
{
    /** @var array<string, array{label: string, description: string}> */
    public const GROUPS = [
        'sops' => [
            'label' => 'SOPs',
            'description' => 'Step-by-step runbooks for the team — no technical background needed.',
        ],
        'technical' => [
            'label' => 'Technical',
            'description' => 'Integration internals and API contracts for the technical team.',
        ],
    ];

    /** @var array<string, array{title: string, group: string, summary: string, view: string, sections: list<array{id: string, title: string}>}> */
    private const DOCS = [
        'salon-setup-sop' => [
            'title' => 'How to set up a new salon — step by step',
            'group' => 'sops',
            'summary' => 'The full walkthrough: BookTheStyle, the GHL sub-account, connecting the two, and the go-live checklist.',
            'view' => 'docs.salon-setup-sop',
            'sections' => [
                ['id' => 'purpose', 'title' => 'Purpose & scope'],
                ['id' => 'before-you-start', 'title' => 'Before you start'],
                ['id' => 'part-a-bookthestyle', 'title' => 'Part A — BookTheStyle'],
                ['id' => 'part-b-ghl', 'title' => 'Part B — GHL'],
                ['id' => 'part-c-connect', 'title' => 'Part C — Connect the two'],
                ['id' => 'verification', 'title' => 'Final verification'],
                ['id' => 'troubleshooting', 'title' => 'Troubleshooting'],
                ['id' => 'escalation', 'title' => 'Escalation'],
                ['id' => 'checklist', 'title' => 'Go-live checklist'],
            ],
        ],
        'technical-integration-reference' => [
            'title' => 'BookTheStyle × GoHighLevel — technical integration reference',
            'group' => 'technical',
            'summary' => 'Endpoints, the Custom Action contract, the token scheme, and the other integration surfaces — verified from the codebase.',
            'view' => 'docs.technical-integration-reference',
            'sections' => [
                ['id' => 'about', 'title' => 'About'],
                ['id' => 'prerequisites', 'title' => 'Prerequisites'],
                ['id' => 'architecture', 'title' => 'Architecture'],
                ['id' => 'booking-sequence', 'title' => 'Booking sequence'],
                ['id' => 'endpoints', 'title' => 'Endpoint reference'],
                ['id' => 'auth', 'title' => 'Token lifecycle'],
                ['id' => 'resilience', 'title' => 'Retries & rate limits'],
                ['id' => 'webhook', 'title' => 'Webhook contract'],
                ['id' => 'ghl-side', 'title' => 'GHL side'],
                ['id' => 'runbook', 'title' => 'Provisioning runbook'],
                ['id' => 'troubleshooting', 'title' => 'Troubleshooting'],
                ['id' => 'glossary', 'title' => 'Glossary'],
                ['id' => 'sync', 'title' => 'Keeping in sync'],
            ],
        ],
    ];

    /**
     * The sidebar: both groups (in declared order) with their docs.
     *
     * @return list<array{key: string, label: string, description: string, docs: list<array{slug: string, title: string, summary: string}>}>
     */
    public function groups(): array
    {
        $out = [];

        foreach (self::GROUPS as $key => $meta) {
            $docs = [];
            foreach (self::DOCS as $slug => $doc) {
                if ($doc['group'] === $key) {
                    $docs[] = ['slug' => $slug, 'title' => $doc['title'], 'summary' => $doc['summary']];
                }
            }
            $out[] = ['key' => $key, 'label' => $meta['label'], 'description' => $meta['description'], 'docs' => $docs];
        }

        return $out;
    }

    /**
     * @return array{slug: string, title: string, group: string, groupLabel: string, summary: string, view: string, sections: list<array{id: string, title: string}>}|null
     */
    public function find(string $slug): ?array
    {
        $doc = self::DOCS[$slug] ?? null;

        if ($doc === null) {
            return null;
        }

        return [
            'slug' => $slug,
            'title' => $doc['title'],
            'group' => $doc['group'],
            'groupLabel' => self::GROUPS[$doc['group']]['label'],
            'summary' => $doc['summary'],
            'view' => $doc['view'],
            'sections' => $doc['sections'],
        ];
    }

    public function firstSlug(): ?string
    {
        return array_key_first(self::DOCS);
    }
}

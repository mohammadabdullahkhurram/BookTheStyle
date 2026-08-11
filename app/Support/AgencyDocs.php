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
            'summary' => 'From nothing to a live salon: BookTheStyle, the GHL side, the Voice AI, the widget — with verification at every step and the go-live checklist.',
            'view' => 'docs.salon-setup-sop',
            'sections' => [
                ['id' => 'how-it-works', 'title' => 'How it fits together'],
                ['id' => 'before-you-start', 'title' => 'Before you start'],
                ['id' => 'part-a-bookthestyle', 'title' => 'A — Create the salon'],
                ['id' => 'part-b-salon-setup', 'title' => 'B — Staff, services, hours'],
                ['id' => 'part-c-ghl', 'title' => 'C — The GHL side'],
                ['id' => 'part-d-connect', 'title' => 'D — Connect the two'],
                ['id' => 'part-e-voice', 'title' => 'E — The Voice AI'],
                ['id' => 'part-f-widget', 'title' => 'F — The widget'],
                ['id' => 'verify-go-live', 'title' => 'Test & go live'],
                ['id' => 'day-to-day', 'title' => 'Day to day'],
                ['id' => 'troubleshooting', 'title' => 'Troubleshooting'],
                ['id' => 'escalation', 'title' => 'Escalation'],
                ['id' => 'checklist', 'title' => 'Go-live checklist'],
            ],
        ],
        'technical-integration-reference' => [
            'title' => 'BookTheStyle × GoHighLevel — technical integration reference',
            'group' => 'technical',
            'summary' => 'The tenancy, the booking engine, all four API endpoints, the webhook, the sync machinery, health & monitoring — every claim owned by a named class.',
            'view' => 'docs.technical-integration-reference',
            'sections' => [
                ['id' => 'about', 'title' => 'About'],
                ['id' => 'architecture', 'title' => 'Architecture & tenancy'],
                ['id' => 'domain-model', 'title' => 'Domain model'],
                ['id' => 'booking-engine', 'title' => 'The booking engine'],
                ['id' => 'endpoints', 'title' => 'Booking API reference'],
                ['id' => 'auth', 'title' => 'Token lifecycle'],
                ['id' => 'webhook', 'title' => 'Webhook contract'],
                ['id' => 'outbound-sync', 'title' => 'Outbound sync'],
                ['id' => 'surfaces', 'title' => 'Widget, feeds & demo'],
                ['id' => 'health', 'title' => 'Health & monitoring'],
                ['id' => 'security-ops', 'title' => 'Security & config'],
                ['id' => 'voice-ai-prompts', 'title' => 'Voice AI Prompts tab'],
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

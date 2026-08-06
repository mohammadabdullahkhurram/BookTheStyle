<?php

use App\Models\Agency;
use App\Support\AgencyDocs;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Internal documentation for the agency team — NATIVE Blade doc pages (the
 * x-docs.* kit), grouped hard into SOPs vs Technical in the sidebar, with
 * on-page section anchors. EVERY agency role may read
 * (AgencyPolicy::viewDocs); salon-only users and guests are refused
 * server-side. Docs are added by committing a Blade view + a registry entry
 * (App\Support\AgencyDocs).
 */
new #[Title('Documentation')] class extends Component {
    public ?string $slug = null;

    public function mount(?string $doc = null): void
    {
        $this->authorize('viewDocs', $this->agency());

        $this->slug = $doc;

        if ($doc !== null && (new AgencyDocs)->find($doc) === null) {
            abort(404);
        }
    }

    public function agency(): Agency
    {
        $agency = Auth::user()?->agency;
        abort_if($agency === null, 403);

        return $agency;
    }

    /** @return list<array{key: string, label: string, description: string, docs: list<array{slug: string, title: string, summary: string}>}> */
    #[Computed]
    public function groups(): array
    {
        return (new AgencyDocs)->groups();
    }

    /** @return array{slug: string, title: string, group: string, groupLabel: string, summary: string, view: string, sections: list<array{id: string, title: string}>}|null */
    #[Computed]
    public function doc(): ?array
    {
        $registry = new AgencyDocs;
        $slug = $this->slug ?? $registry->firstSlug();

        return $slug === null ? null : $registry->find($slug);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Agency')" :title="__('Documentation')">
            <x-slot:subtitle>{{ __('Internal runbooks and technical references for the agency team.') }}</x-slot:subtitle>
        </x-ui.page-header>

        <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
            {{-- The index: TWO hard groups — SOPs and Technical — each with
                 its own labelled card, so which-is-which is instant. --}}
            <nav class="flex w-full shrink-0 flex-col gap-4 lg:w-72" aria-label="{{ __('Documentation index') }}">
                @foreach ($this->groups as $group)
                    <x-ui.card padding="p-4" class="flex flex-col gap-2">
                        <div>
                            <p class="bts-overline">{{ $group['label'] }}</p>
                            <p class="mt-0.5 text-[12.5px] leading-snug text-faint">{{ $group['description'] }}</p>
                        </div>
                        <div class="flex flex-col gap-1">
                            @foreach ($group['docs'] as $entry)
                                @php($active = $this->doc && $this->doc['slug'] === $entry['slug'])
                                <a href="{{ route('agency.docs', ['doc' => $entry['slug']]) }}" wire:navigate
                                   @class(['rounded-[8px] px-2.5 py-2 transition', 'bg-muted' => $active, 'hover:bg-muted/60' => ! $active])>
                                    <span @class(['block text-[13.5px] leading-snug', 'font-semibold text-ink' => $active, 'font-medium text-secondary' => ! $active])>{{ $entry['title'] }}</span>
                                    <span class="mt-0.5 block text-[12px] leading-snug text-faint">{{ $entry['summary'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endforeach
            </nav>

            {{-- The reading view: group badge, title, on-page anchors, then
                 the native doc content (x-docs.* components inside .bts-doc). --}}
            @if ($this->doc)
                <x-ui.card class="min-w-0 flex-1">
                    <article class="bts-doc">
                        <div class="mb-1 flex items-center gap-2">
                            <span class="bts-pill" style="background-color:var(--accent-tint);color:var(--accent-ink);">{{ $this->doc['groupLabel'] }}</span>
                        </div>
                        <h1>{{ $this->doc['title'] }}</h1>

                        @if (count($this->doc['sections']) > 1)
                            <div class="mb-6 flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-divider pb-4">
                                <span class="me-1 text-[12px] font-semibold uppercase tracking-[0.08em] text-faint">{{ __('On this page') }}</span>
                                @foreach ($this->doc['sections'] as $section)
                                    <a href="#{{ $section['id'] }}" class="rounded-full border border-input-border px-2.5 py-0.5 text-[12.5px] font-medium text-secondary transition hover:text-ink">{{ $section['title'] }}</a>
                                @endforeach
                            </div>
                        @endif

                        @include($this->doc['view'])
                    </article>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>

<?php

use App\Models\Agency;
use App\Support\AgencyDocs;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Internal documentation for the agency team: a sidebar index of markdown
 * docs (resources/docs) and a rendered reading view. EVERY agency role may
 * read (AgencyPolicy::viewDocs — owners, admins, delegated agency_users);
 * salon-only users and guests are refused server-side. Read-only this pass:
 * docs are added by committing a markdown file; an in-app editor is a
 * future phase.
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

    #[Computed]
    public function index()
    {
        return (new AgencyDocs)->all()->groupBy('category');
    }

    /** @return array{slug: string, title: string, category: string, html: string}|null */
    #[Computed]
    public function doc(): ?array
    {
        // No selection: open the first doc in the index, if any exist.
        $slug = $this->slug ?? (new AgencyDocs)->all()->first()['slug'] ?? null;

        return $slug === null ? null : (new AgencyDocs)->find($slug);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Agency')" :title="__('Documentation')">
            <x-slot:subtitle>{{ __('Internal technical docs and standard operating procedures.') }}</x-slot:subtitle>
        </x-ui.page-header>

        @if ($this->index->isEmpty())
            <x-ui.card class="py-12 text-center">
                <p class="text-[15px] font-medium text-body">{{ __('No documentation yet.') }}</p>
                <p class="mt-1 text-[13.5px] text-secondary">{{ __('Docs are added by committing markdown files to resources/docs.') }}</p>
            </x-ui.card>
        @else
            <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
                {{-- Doc index: grouped by category, current doc highlighted. --}}
                <nav class="w-full shrink-0 lg:w-64" aria-label="{{ __('Documentation index') }}">
                    <x-ui.card padding="p-4" class="flex flex-col gap-4">
                        @foreach ($this->index as $category => $docs)
                            <div class="flex flex-col gap-1">
                                <p class="bts-overline px-2">{{ $category }}</p>
                                @foreach ($docs as $entry)
                                    <a href="{{ route('agency.docs', ['doc' => $entry['slug']]) }}" wire:navigate
                                       @class(['rounded-[8px] px-2 py-1.5 text-[13.5px] transition', 'bg-muted font-semibold text-ink' => $this->doc && $this->doc['slug'] === $entry['slug'], 'text-secondary hover:text-ink' => ! $this->doc || $this->doc['slug'] !== $entry['slug']])>
                                        {{ $entry['title'] }}
                                    </a>
                                @endforeach
                            </div>
                        @endforeach
                    </x-ui.card>
                </nav>

                {{-- Reading view: rendered markdown, styled by .bts-doc. The
                     HTML is produced by AgencyDocs with raw input stripped —
                     safe to render unescaped. --}}
                @if ($this->doc)
                    <x-ui.card class="min-w-0 flex-1">
                        <article class="bts-doc">
                            <h1>{{ $this->doc['title'] }}</h1>
                            {!! $this->doc['html'] !!}
                        </article>
                    </x-ui.card>
                @endif
            </div>
        @endif
    </div>
</div>

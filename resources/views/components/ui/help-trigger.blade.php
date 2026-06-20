@props(['doc', 'label' => null])

{{-- A highlighted, clearly tappable "watch how" pill that opens x-ui.help-modal.
     Resolves the doc from the help registry; an unknown key renders nothing
     (safe). The default slot is the actionable content shown beside the video
     in the modal (e.g. the subscribe link + steps). --}}
@php($helpDoc = \App\Support\HelpDocs::find($doc))

@if ($helpDoc)
    <div x-data="{ helpOpen: false }" class="contents">
        <button type="button" @click="helpOpen = true"
                {{ $attributes->class('inline-flex items-center gap-1.5 rounded-full bg-accent-tint px-3.5 py-1.5 text-[13px] font-semibold text-accent-ink transition hover:bg-accent hover:text-white') }}>
            <flux:icon.play variant="micro" class="shrink-0" />
            <span>{{ $label ?? $helpDoc->title }}</span>
        </button>

        <x-ui.help-modal :doc="$helpDoc">{{ $slot }}</x-ui.help-modal>
    </div>
@endif

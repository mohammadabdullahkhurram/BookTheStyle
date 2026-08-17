{{-- Agency-only: the full health check entry card — included with the parent Settings component's scope. --}}
{{-- Agency operators only: the one-click full-setup validation. --}}
<x-ui.card class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h2 class="bts-card-title">{{ __('Health check') }}</h2>
        <p class="mt-1 text-[13.5px] text-secondary">{{ __('Validate the whole setup — integrations, notifications, scheduler & queue, salon readiness, system — with disposable test records.') }}</p>
    </div>
    {{-- Same page — the hash flips the top-level tab via the settings
         hashchange listener. --}}
    <x-ui.button href="#health">{{ __('Open') }}</x-ui.button>
</x-ui.card>

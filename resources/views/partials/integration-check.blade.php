{{-- One integration "Test"/"Verify" control: button + loading state +
     inline result + "Last verified X ago". The host Livewire component must
     expose a public Salon $salon and a runIntegrationCheck(string $key)
     action (settings + setup wizard both do), so the SAME include works on
     either surface. Usage:
       @include('partials.integration-check', ['check' => 'mapping', 'label' => __('Verify mapping')])
     Optional: 'blocked' => bool, 'blockedNote' => string — renders the button
     disabled with an explanatory needs-public-URL note (the feature works
     unchanged once the app runs on its live URL). --}}
@php
    $blocked = $blocked ?? false;
    $result = $salon->integration_checks[$check] ?? null;
    $ranAt = is_array($result) && filled($result['at'] ?? null) ? \Carbon\CarbonImmutable::parse($result['at']) : null;
@endphp

<div class="flex flex-col gap-2.5" data-integration-check="{{ $check }}">
    <div class="flex flex-wrap items-center gap-3">
        <button type="button"
                wire:click="runIntegrationCheck('{{ $check }}')"
                wire:loading.attr="disabled" wire:target="runIntegrationCheck('{{ $check }}')"
                @if ($blocked) disabled aria-disabled="true" @endif
                class="bts-btn bts-btn-sm bts-btn-secondary">
            <flux:icon.loading variant="micro" class="shrink-0" wire:loading wire:target="runIntegrationCheck('{{ $check }}')" />
            <span wire:loading.remove wire:target="runIntegrationCheck('{{ $check }}')">{{ $label }}</span>
            <span wire:loading wire:target="runIntegrationCheck('{{ $check }}')">{{ __('Checking…') }}</span>
        </button>
        @if ($ranAt)
            <span class="text-[12.5px] text-faint">{{ __('Last verified :time', ['time' => $ranAt->diffForHumans()]) }}</span>
        @endif
    </div>

    @include('partials.integration-check-result', [
        'result' => $result,
        'blocked' => $blocked,
        'blockedNote' => $blockedNote ?? null,
    ])
</div>

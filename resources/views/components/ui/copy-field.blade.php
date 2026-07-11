@props([
    'label',
    'value',
    'mono' => true,
])

{{-- A labelled copy-paste value: monospace text + a copy button with
     "Copied" feedback. Falls back silently if the clipboard API is
     unavailable (non-HTTPS) — the value stays selectable either way. --}}
<div class="flex items-center gap-3 rounded-[11px] border border-input bg-field px-3 py-2.5">
    <div class="min-w-0 flex-1">
        <p class="text-[12.5px] font-semibold text-secondary">{{ $label }}</p>
        <p class="{{ $mono ? 'font-mono' : '' }} select-all break-all text-[13px] text-ink">{{ $value }}</p>
    </div>
    <div x-data="{ copied: false }" class="shrink-0">
        <button type="button"
                class="bts-btn bts-btn-secondary bts-btn-sm"
                @click="navigator.clipboard?.writeText(@js($value)).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => {})"
                :aria-label="copied ? '{{ __('Copied') }}' : '{{ __('Copy :label', ['label' => $label]) }}'">
            <span x-show="!copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak class="text-success">{{ __('Copied') }}</span>
        </button>
    </div>
</div>

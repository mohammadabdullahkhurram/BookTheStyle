@props(['name' => null, 'password', 'showHeading' => true])

<div class="rounded-[16px] border border-accent/30 bg-accent-tint p-5">
    @if ($showHeading)
        <h3 class="bts-card-title text-[16px]">
            {{ $name ? __('Temporary password for :name', ['name' => $name]) : __('Temporary password') }}
        </h3>
    @endif
    <p class="@if ($showHeading) mt-1 @endif text-[14px] text-secondary">
        {{ __('Shown once. Copy it now and share it securely — it was also emailed. The user must change it on first login.') }}
    </p>

    <div class="mt-3 flex items-center gap-2" x-data>
        <code x-ref="pw" class="flex-1 rounded-[11px] border border-border bg-card px-3 py-2.5 font-mono text-[14px] text-ink">{{ $password }}</code>
        <x-ui.button size="sm" variant="secondary" x-on:click="navigator.clipboard?.writeText($refs.pw.textContent.trim())">
            <flux:icon.clipboard variant="micro" class="shrink-0" />{{ __('Copy') }}
        </x-ui.button>
    </div>
</div>

@props(['name' => null, 'password'])

<div class="rounded-xl border border-accent/40 bg-accent-soft p-5">
    <flux:heading size="sm">
        {{ $name ? __('Temporary password for :name', ['name' => $name]) : __('Temporary password') }}
    </flux:heading>
    <flux:text class="mt-1 text-sm text-secondary">
        {{ __('Shown once. Copy it now and share it securely — it was also emailed. The user must change it on first login.') }}
    </flux:text>

    <div class="mt-3 flex items-center gap-2" x-data>
        <code x-ref="pw" class="flex-1 rounded-lg border border-border bg-card px-3 py-2 font-mono text-sm text-ink">{{ $password }}</code>
        <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard?.writeText($refs.pw.textContent.trim())">
            {{ __('Copy') }}
        </flux:button>
    </div>
</div>

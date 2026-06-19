@props([
    'title',
    'overline' => null,
    'back' => null,
])

<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        @if ($overline)
            <div class="text-[12.5px] font-semibold uppercase tracking-[0.04em] text-faint">{{ $overline }}</div>
        @endif
        <h1 class="font-display text-[26px] font-bold leading-none text-ink {{ $overline ? 'mt-1.5' : '' }}">{{ $title }}</h1>
        @isset($subtitle)
            <p class="mt-2 text-[15px] text-secondary">{{ $subtitle }}</p>
        @endisset
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>

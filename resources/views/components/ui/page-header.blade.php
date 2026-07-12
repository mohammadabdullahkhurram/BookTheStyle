@props([
    'title',
    'overline' => null,
    'back' => null,
])

{{-- Editorial page header: wide-tracked plum overline (the signature detail)
     over a Fraunces display title, with room to breathe. --}}
<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        @if ($overline)
            <div class="bts-overline">{{ $overline }}</div>
        @endif
        <h1 class="font-display text-[28px] font-semibold leading-[1.1] text-ink {{ $overline ? 'mt-2' : '' }}">{{ $title }}</h1>
        @isset($subtitle)
            <p class="mt-2 max-w-xl text-[15px] leading-relaxed text-secondary">{{ $subtitle }}</p>
        @endisset
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>

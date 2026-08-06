{{-- A numbered step: plum step badge, title, body slot. --}}
@props(['n', 'title'])

<div class="mb-4 flex gap-4">
    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[13px] font-semibold" style="background-color:var(--accent-tint);color:var(--accent-ink);">{{ $n }}</div>
    <div class="min-w-0 flex-1 pt-0.5">
        <p class="mb-1 text-[15px] font-semibold text-ink">{{ $title }}</p>
        <div class="bts-doc-step-body text-[14px] leading-relaxed text-secondary">{{ $slot }}</div>
    </div>
</div>

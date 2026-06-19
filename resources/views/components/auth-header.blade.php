@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-1.5 text-center">
    <h1 class="font-display text-[22px] font-bold text-ink">{{ $title }}</h1>
    <p class="text-[15px] text-secondary">{{ $description }}</p>
</div>

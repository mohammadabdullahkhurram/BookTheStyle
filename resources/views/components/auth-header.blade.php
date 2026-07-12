@props([
    'title',
    'description',
])

{{-- Editorial auth heading: Fraunces title under the boutique overline. --}}
<div class="flex w-full flex-col gap-2 text-center">
    <div class="bts-overline">{{ __('BookTheStyle') }}</div>
    <h1 class="font-display text-[24px] font-semibold leading-tight text-ink">{{ $title }}</h1>
    <p class="text-[15px] leading-relaxed text-secondary">{{ $description }}</p>
</div>

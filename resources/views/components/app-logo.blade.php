@props([
    'alt' => 'BookTheStyle',
    'compact' => false,
])

@if ($compact)
    {{-- Small-size lockup. The raster lockup (full-logo.png) uses the
         scissors-B mark AS the word's first letter — a trick that stops
         reading as a B below roughly 40px tall (the scissors erase the
         bowls and it scans as "ROOKTHESTYLE"). At sidebar sizes the mark
         becomes decoration and the full word is real, crisp text in the
         brand's tracked-caps voice. Size via a height class on the caller
         (h-7/h-8), same contract as the image variant. --}}
    {{-- Metrics chosen to clear the 220px sidebar WITH the collapse chevron
         beside it: icon + 12.5px tracked caps ≈ 145px of the ~148px slot. --}}
    <span {{ $attributes->class('inline-flex select-none items-center gap-1.5') }} role="img" aria-label="{{ $alt }}">
        <img src="/images/icon-logo.png" alt="" width="512" height="512" class="h-full w-auto" />
        <span class="whitespace-nowrap text-[12.5px] font-semibold uppercase tracking-[0.12em] text-ink" aria-hidden="true">BookTheStyle</span>
    </span>
@else
    {{-- BookTheStyle full lockup (icon + wordmark, horizontal). The image lives at
         public/images/full-logo.png — replace that one file (a transparent PNG) to
         update the lockup everywhere. Set the height on the caller (e.g. class="h-8");
         w-auto + intrinsic width/height keep the aspect ratio and avoid layout shift. --}}
    <img
        src="/images/full-logo.png"
        alt="{{ $alt }}"
        width="891"
        height="189"
        {{ $attributes->class('inline-block w-auto select-none') }}
    />
@endif

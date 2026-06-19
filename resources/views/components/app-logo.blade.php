@props([
    'alt' => 'BookTheStyle',
])

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

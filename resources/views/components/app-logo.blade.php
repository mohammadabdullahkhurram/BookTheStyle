@props([
    'chip' => true,            // show the brand-tint square behind the mark
    'mono' => false,           // single-colour (currentColor) lockup for dark surfaces
    'chipClass' => 'size-9 rounded-[12px]',
    'iconClass' => 'size-5',
    'wordmarkClass' => 'text-[18px]',
])

{{-- Full BookTheStyle lockup: icon mark + wordmark, horizontal. The wordmark is
     live text (crisp, selectable, a11y/SEO-native) in the display grotesk, so no
     visually-hidden duplicate is needed. Two-tone on light surfaces ("Book" in
     accent, "TheStyle" in ink); pass :mono on a dark surface to render the whole
     lockup in currentColor (set text-white on the parent). --}}
<span {{ $attributes->class('inline-flex items-center gap-2.5') }}>
    @if ($mono)
        <x-app-logo-icon class="{{ $iconClass }}" />
        <span class="font-display font-extrabold tracking-[-0.015em] {{ $wordmarkClass }}">BookTheStyle</span>
    @else
        @if ($chip)
            <span class="flex items-center justify-center bg-accent-tint {{ $chipClass }}">
                <x-app-logo-icon class="{{ $iconClass }} text-accent" />
            </span>
        @else
            <x-app-logo-icon class="{{ $iconClass }} text-accent" />
        @endif
        <span class="font-display font-extrabold tracking-[-0.015em] {{ $wordmarkClass }}">
            <span class="text-accent">Book</span><span class="text-ink">TheStyle</span>
        </span>
    @endif
</span>

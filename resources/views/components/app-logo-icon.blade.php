@props([
    'label' => null,
])

{{-- BookTheStyle icon mark: a rounded "B" letterform with an integrated pair of
     scissors. Recreated as a clean vector (the supplied raster was an unusable
     flattened export). No hardcoded fills — stroke/fill follow `currentColor`,
     so each placement sets the colour for its background:
       light surface → text-accent (brand purple);  dark/accent chip → text-white.
     When the mark stands in for the brand name, pass :label so it carries an
     accessible name; otherwise it is decorative (aria-hidden). --}}
<svg
    viewBox="0 0 48 48"
    xmlns="http://www.w3.org/2000/svg"
    fill="none"
    stroke="currentColor"
    stroke-width="2.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif
    {{ $attributes->class('shrink-0') }}
>
    {{-- B letterform: spine + two bowls --}}
    <path d="M15 10V38" />
    <path d="M15 10h9a7 7 0 0 1 0 14h-9" />
    <path d="M15 24h10.5a7.5 7.5 0 0 1 0 14H15" />
    {{-- Scissors: finger rings + crossing blades --}}
    <circle cx="36" cy="14" r="3" />
    <circle cx="36" cy="34" r="3" />
    <path d="M33.7 15.9 18 29" />
    <path d="M33.7 32.1 18 19" />
    <circle cx="24" cy="24" r="1.5" fill="currentColor" stroke="none" />
</svg>

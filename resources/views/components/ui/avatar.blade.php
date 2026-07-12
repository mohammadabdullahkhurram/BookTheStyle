@props([
    'name' => '',
    'seed' => 0,
    'size' => 'md',
])

@php
    $family = \App\Support\PastelPalette::forSeed((int) $seed);
    $initials = collect(preg_split('/\s+/', trim((string) $name)))
        ->filter()
        ->take(2)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');
    $dim = match ($size) {
        'sm' => 'size-8 text-[12px]',
        'lg' => 'size-14 text-[18px]',
        default => 'size-9 text-[13px]',
    };
@endphp

{{-- Family pastel bg + family ink initials: the only pairing in the palette
     that clears WCAG AA (5.2–6.9:1; white-on-avatar was as low as 2.2:1).
     The border keeps the chip defined; the family still identifies the person. --}}
<span {{ $attributes->merge(['class' => "inline-flex {$dim} shrink-0 items-center justify-center rounded-full border font-semibold"]) }}
      style="background-color: {{ $family['bg'] }}; border-color: {{ $family['avatar'] }}; color: {{ $family['ink'] }};">{{ $initials }}</span>

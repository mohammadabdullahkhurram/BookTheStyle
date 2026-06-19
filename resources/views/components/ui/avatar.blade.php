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

<span {{ $attributes->merge(['class' => "inline-flex {$dim} shrink-0 items-center justify-center rounded-full font-semibold text-white"]) }}
      style="background-color: {{ $family['avatar'] }};">{{ $initials }}</span>

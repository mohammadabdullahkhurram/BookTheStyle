@props([
    'label',
    'value',
    'sublabel' => null,
    'tone' => 'ink',
])

@php
    $tones = [
        'ink' => '#1C1B1A',
        'info' => '#356088',
        'success' => '#3E5C3A',
        'warning' => '#8A5A1E',
        'danger' => '#A23A3A',
    ];
    $color = $tones[$tone] ?? $tones['ink'];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-[16px] border border-border bg-card p-[18px] shadow-card']) }}>
    <div class="text-[15px] text-secondary">{{ $label }}</div>
    <div class="mt-2 font-display text-[34px] font-bold leading-none" style="color: {{ $color }};">{{ $value }}</div>
    @if ($sublabel)
        <div class="mt-2 text-[14px] text-faint">{{ $sublabel }}</div>
    @endif
</div>

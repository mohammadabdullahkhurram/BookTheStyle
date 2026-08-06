{{-- Tip / warning / note callout, Marble-toned. --}}
@props(['type' => 'note', 'title' => null])

@php
    [$bg, $border, $ink, $label] = match ($type) {
        'tip' => ['#E7EFE4', '#C8DAC2', '#3E5C3A', __('Tip')],
        'warning' => ['#F6E8E1', '#E4C4B3', '#8A4B2D', __('Important')],
        default => ['#E3EDF6', '#C4D6E8', '#356088', __('Note')],
    };
@endphp

<div class="mb-4 rounded-[10px] border px-4 py-3" style="background-color:{{ $bg }};border-color:{{ $border }};">
    <p class="mb-0.5 text-[12px] font-semibold uppercase tracking-[0.08em]" style="color:{{ $ink }};">{{ $title ?? $label }}</p>
    <div class="text-[13.5px] leading-relaxed" style="color:{{ $ink }};">{{ $slot }}</div>
</div>

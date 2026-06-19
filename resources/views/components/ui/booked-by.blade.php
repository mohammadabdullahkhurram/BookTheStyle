@props([
    'label',
    'sub' => null,
    'source' => null,
])

@php
    // Source-keyed dot (DESIGN-TOKENS pastel hues): app=neutral, voice=violet,
    // chat=green, ghl=amber.
    $dots = [
        'in_app' => '#A09C94',
        'voice_ai' => '#8C7FE0',
        'chat_widget' => '#6E9968',
        'ghl_manual' => '#D49A4E',
    ];
    $value = $source instanceof \App\Enums\BookingSource ? $source->value : (string) $source;
    $dot = $dots[$value] ?? '#A09C94';
@endphp

<div {{ $attributes->merge(['class' => 'flex items-start gap-2 text-[14px] text-secondary']) }}>
    <span class="mt-1.5 size-1.5 shrink-0 rounded-full" style="background-color: {{ $dot }};"></span>
    <span>
        {{ $label }}
        @if ($sub)<span class="block text-faint">{{ $sub }}</span>@endif
    </span>
</div>

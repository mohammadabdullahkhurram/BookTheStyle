@props(['status'])

@php
    // Exact DESIGN-TOKENS status colors. Confirmed maps to the Arrived (info)
    // family since it is a positive pre-arrival state not in the token list.
    $map = [
        'booked' => ['#F0EEEA', '#6B6862'],
        'confirmed' => ['#E3EDF6', '#356088'],
        'arrived' => ['#E3EDF6', '#356088'],
        'in_service' => ['#FBEFD6', '#8A5A1E'],
        'completed' => ['#E7EFE4', '#3E5C3A'],
        'cancelled' => ['#F0EEEA', '#9C9890'],
        'no_show' => ['#F8E3E3', '#A23A3A'],
    ];

    $value = $status instanceof \App\Enums\BookingStatus ? $status->value : (string) $status;
    [$bg, $fg] = $map[$value] ?? $map['booked'];
    $label = $status instanceof \App\Enums\BookingStatus ? $status->label() : ucfirst(str_replace('_', ' ', $value));
@endphp

<span {{ $attributes->merge(['class' => 'bts-pill']) }} style="background-color: {{ $bg }}; color: {{ $fg }};">{{ $label }}</span>

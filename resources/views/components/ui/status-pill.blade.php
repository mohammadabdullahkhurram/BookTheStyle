@props(['status'])

@php
    // Exact DESIGN-TOKENS status colors. Confirmed maps to the Arrived (info)
    // family since it is a positive pre-arrival state not in the token list.
    // Cancelled text is #6B6862 (4.79:1 on its bg — the spec's #9C9890 fails AA).
    $map = [
        'booked' => ['#F0EEEA', '#6B6862'],
        'confirmed' => ['#E3EDF6', '#356088'],
        'arrived' => ['#E3EDF6', '#356088'],
        'in_service' => ['#FBEFD6', '#8A5A1E'],
        'completed' => ['#E7EFE4', '#3E5C3A'],
        'cancelled' => ['#F0EEEA', '#6B6862'],
        'no_show' => ['#F8E3E3', '#A23A3A'],
    ];

    $value = $status instanceof \App\Enums\BookingStatus ? $status->value : (string) $status;
    // Unknown statuses fall back to a neutral pill with their own humanised
    // label (body ink, not Booked-grey) — never silently masquerade as Booked.
    [$bg, $fg] = $map[$value] ?? ['#F0EEEA', '#56534C'];
    $label = $status instanceof \App\Enums\BookingStatus ? $status->label() : ucfirst(str_replace('_', ' ', $value));
@endphp

<span {{ $attributes->merge(['class' => 'bts-pill']) }} style="background-color: {{ $bg }}; color: {{ $fg }};">{{ $label }}</span>

{{-- Voice-AI booking sequence — four lifelines, hand-authored inline SVG. --}}
<svg viewBox="0 0 760 470" role="img" aria-label="Voice AI booking sequence: the caller talks to the Voice AI, which checks availability and creates the booking through the BookTheStyle API; GHL then sends the confirmation" style="min-width:640px;width:100%;height:auto;font-family:inherit;">
    <defs>
        <marker id="sq-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
            <path d="M 0 1 L 9 5 L 0 9 z" fill="#824C71" />
        </marker>
        <marker id="sq-arrow-soft" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
            <path d="M 0 1 L 9 5 L 0 9 z" fill="#9C968D" />
        </marker>
    </defs>

    @php
        $lanes = [['x' => 90, 'label' => 'Caller'], ['x' => 285, 'label' => 'GHL Voice AI'], ['x' => 490, 'label' => 'BookTheStyle API'], ['x' => 672, 'label' => 'GHL contacts / workflows']];
    @endphp
    @foreach ($lanes as $lane)
        <rect x="{{ $lane['x'] - 78 }}" y="14" width="156" height="32" rx="8" fill="#F7F5F2" stroke="#D8D3CB" />
        <text x="{{ $lane['x'] }}" y="35" text-anchor="middle" font-size="12" font-weight="600" fill="#2B2A28">{{ $lane['label'] }}</text>
        <line x1="{{ $lane['x'] }}" y1="46" x2="{{ $lane['x'] }}" y2="450" stroke="#D8D3CB" stroke-dasharray="4 4" />
    @endforeach

    @php
        // [fromX, toX, y, label, solid?]
        $messages = [
            [90, 285, 84, '“A haircut Friday afternoon, please”', true],
            [285, 490, 122, 'POST /api/v1/booking/availability — service, date range', true],
            [490, 285, 158, '200 · open slots, each with a spoken label', false],
            [285, 90, 194, 'offers the available times', true],
            [90, 285, 230, 'picks a slot, gives name + phone', true],
            [285, 490, 266, 'POST /api/v1/booking/create — slot, client, service', true],
            [490, 285, 302, '201 · booking_id + spoken confirmation', false],
            [285, 90, 338, 'confirms the appointment verbally', true],
            [490, 672, 374, 'contact upsert + tag', true],
            [672, 90, 412, 'confirmation SMS / email via Workflow', true],
        ];
    @endphp
    @foreach ($messages as [$from, $to, $y, $label, $solid])
        <line x1="{{ $from }}" y1="{{ $y }}" x2="{{ $to + ($to > $from ? -4 : 4) }}" y2="{{ $y }}"
            stroke="{{ $solid ? '#824C71' : '#9C968D' }}" stroke-width="1.5"
            @unless ($solid) stroke-dasharray="5 4" @endunless
            marker-end="url(#{{ $solid ? 'sq-arrow' : 'sq-arrow-soft' }})" />
        <text x="{{ ($from + $to) / 2 }}" y="{{ $y - 7 }}" text-anchor="middle" font-size="10.5" fill="{{ $solid ? '#824C71' : '#6B6862' }}">{{ $label }}</text>
    @endforeach
</svg>

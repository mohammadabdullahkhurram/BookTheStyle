{{-- App showcase: the per-stylist day calendar, as a hand-built framed
     mockup (real proportions, the app's pastel stylist families). --}}
@php($cols = [
    ['name' => 'Simone', 'initials' => 'SI', 'avatar' => '#6E9968', 'bg' => '#E7EFE4', 'border' => '#D5E4D0', 'ink' => '#3E5C3A', 'blocks' => [['t' => '9:00', 'n' => 'Brenda M.', 'top' => 8, 'h' => 52], ['t' => '10:30', 'n' => 'Craig M.', 'top' => 104, 'h' => 78]]],
    ['name' => 'Maya', 'initials' => 'MA', 'avatar' => '#C76A8C', 'bg' => '#FBE7EE', 'border' => '#F2D2DE', 'ink' => '#8E3D5A', 'blocks' => [['t' => '9:30', 'n' => 'Alena G.', 'top' => 44, 'h' => 62], ['t' => '12:30', 'n' => 'Desirae S.', 'top' => 184, 'h' => 52]]],
    ['name' => 'Jonah', 'initials' => 'JO', 'avatar' => '#D49A4E', 'bg' => '#FBEFD6', 'border' => '#EEDDB6', 'ink' => '#8A5A1E', 'blocks' => [['t' => '11:00', 'n' => 'Megan W.', 'top' => 128, 'h' => 62]]],
    ['name' => 'Elise', 'initials' => 'EL', 'avatar' => '#8C7FE0', 'bg' => '#EAE6FB', 'border' => '#D8D1F2', 'ink' => '#4B3F9E', 'blocks' => [['t' => '9:00', 'n' => 'James H.', 'top' => 8, 'h' => 62], ['t' => '10:30', 'n' => 'Amy J.', 'top' => 104, 'h' => 78]]],
])
<div class="overflow-hidden rounded-[20px] border border-border bg-card" style="box-shadow: 0 24px 60px rgba(28,27,26,.10);">
    <div class="flex items-center gap-2 border-b border-divider px-4 py-3">
        <span class="size-[11px] rounded-full bg-[#E6A6A6]"></span>
        <span class="size-[11px] rounded-full bg-[#EBCF9A]"></span>
        <span class="size-[11px] rounded-full bg-[#A9D2A4]"></span>
        <div class="flex flex-1 justify-center"><div class="rounded-full bg-row px-3.5 py-1 text-[12.5px] text-faint">app.bookthestyle.com</div></div>
    </div>
    <div class="flex min-h-[300px]">
        <div class="flex w-12 shrink-0 flex-col items-end gap-[34px] border-e border-row pe-2 pt-[44px]">
            @foreach (['9', '10', '11', '12', '1'] as $h)
                <span class="font-display text-[11px] text-faint">{{ $h }}</span>
            @endforeach
        </div>
        @foreach ($cols as $col)
            <div class="relative flex-1 border-e border-row last:border-e-0">
                <div class="flex items-center gap-2 border-b border-row px-3 py-2.5">
                    <span class="flex size-[22px] items-center justify-center rounded-full font-display text-[9px] font-semibold text-white" style="background-color: {{ $col['avatar'] }};">{{ $col['initials'] }}</span>
                    <span class="text-[12.5px] font-semibold text-ink">{{ $col['name'] }}</span>
                </div>
                <div class="relative h-[240px] p-2">
                    @foreach ($col['blocks'] as $b)
                        <div class="absolute inset-x-2 overflow-hidden rounded-[9px] border px-2.5 py-1.5" style="top: {{ $b['top'] }}px; height: {{ $b['h'] }}px; background-color: {{ $col['bg'] }}; border-color: {{ $col['border'] }};">
                            <div class="font-display text-[10px] font-semibold" style="color: {{ $col['ink'] }};">{{ $b['t'] }}</div>
                            <div class="text-[11.5px] font-semibold text-[#2a2724]">{{ $b['n'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>

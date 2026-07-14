{{-- App showcase: the Today screen in the app's Marble theme — butter-cream
     surfaces, coral accent, pressed-clay tiles, real status pills. Static
     mockup: the exact palette the product ships with. --}}
<div class="overflow-hidden rounded-[20px] border border-border" style="background-color: #FFF8EF; box-shadow: 0 24px 60px rgba(28,27,26,.10);">
    <div class="flex items-center gap-2 border-b px-4 py-3" style="border-color: #F2E2CD; background-color: #FFFDF8;">
        <span class="size-[11px] rounded-full bg-[#E6A6A6]"></span>
        <span class="size-[11px] rounded-full bg-[#EBCF9A]"></span>
        <span class="size-[11px] rounded-full bg-[#A9D2A4]"></span>
        <div class="flex flex-1 justify-center"><div class="rounded-full px-3.5 py-1 text-[12.5px]" style="background-color: #F7ECD9; color: #846D5A;">app.bookthestyle.com/today</div></div>
    </div>
    <div class="p-5">
        <div class="font-display text-[19px] font-bold" style="color: #4A382E;">{{ __('Today') }}</div>
        <div class="mt-3 grid grid-cols-3 gap-3">
            @foreach ([[__('Booked'), '12', '#4A382E'], [__('Arrived'), '3', '#356088'], [__('Completed'), '6', '#3E5C3A']] as [$label, $value, $tone])
                <div class="rounded-[18px] border-2 px-4 py-3" style="background-color: #FFFDF8; border-color: #F2E2CD; box-shadow: 0 3px 0 #F2E2CD;">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.06em]" style="color: #7A6250;">{{ $label }}</div>
                    <div class="font-display text-[26px] font-bold leading-tight" style="color: {{ $tone }};">{{ $value }}</div>
                </div>
            @endforeach
        </div>
        <div class="mt-4 flex flex-col gap-2">
            @foreach ([['Brenda Miles', __('Hair cut · 9:00 with Maya'), __('Completed'), '#E7EFE4', '#3E5C3A'], ['Megan Wu', __('Nails · 12:00 with Elise'), __('In service'), '#FBEFD6', '#8A5A1E'], ['Amy Jenner', __('Blowout · 1:00 with Sofia'), __('Arrived'), '#E3EDF6', '#356088']] as [$client, $meta, $pill, $pillBg, $pillInk])
                <div class="flex items-center gap-3 rounded-[16px] border-2 px-4 py-3" style="background-color: #FFFDF8; border-color: #F2E2CD; box-shadow: 0 3px 0 #F2E2CD;">
                    <div>
                        <div class="text-[14px] font-semibold" style="color: #4A382E;">{{ $client }}</div>
                        <div class="text-[12.5px]" style="color: #7A6250;">{{ $meta }}</div>
                    </div>
                    <div class="flex-1"></div>
                    <span class="rounded-full px-3 py-1 text-[12px] font-semibold" style="background-color: {{ $pillBg }}; color: {{ $pillInk }};">{{ $pill }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

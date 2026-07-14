{{-- App showcase: the Today dashboard, styled in the SITE's own language
     (white card, plum accent, the open editorial stat rules, the app's real
     status-pill pastels) so it sits naturally on the marketing pages.
     Richer than a teaser: stats, the check-in list, and the source mix. --}}
<div class="overflow-hidden rounded-[20px] border border-border bg-card" style="box-shadow: 0 24px 60px rgba(28,27,26,.10);">
    <div class="flex items-center gap-2 border-b border-divider px-4 py-3">
        <span class="size-[11px] rounded-full bg-[#E6A6A6]"></span>
        <span class="size-[11px] rounded-full bg-[#EBCF9A]"></span>
        <span class="size-[11px] rounded-full bg-[#A9D2A4]"></span>
        <div class="flex flex-1 justify-center"><div class="rounded-full bg-row px-3.5 py-1 text-[12.5px] text-faint">app.bookthestyle.com/today</div></div>
    </div>
    <div class="p-5 sm:p-6">
        <div class="flex items-center gap-3">
            <div>
                <div class="font-display text-[19px] font-bold text-ink">{{ __('Today') }}</div>
                <div class="text-[12.5px] text-faint">{{ __('Tuesday · 4 stylists on') }}</div>
            </div>
            <div class="flex-1"></div>
            <span class="rounded-full bg-accent px-3.5 py-1.5 text-[12px] font-semibold text-white">{{ __('New booking') }}</span>
        </div>

        {{-- Open editorial stats — the app's ruled stat language. --}}
        <div class="mt-5 grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-4">
            @foreach ([[__('Booked'), '12', 'var(--color-ink)'], [__('Arrived'), '3', '#356088'], [__('In service'), '2', '#8A5A1E'], [__('Completed'), '6', '#3E5C3A']] as [$label, $value, $tone])
                <div class="border-t border-divider pt-2.5">
                    <div class="text-[10.5px] font-semibold uppercase tracking-[0.06em] text-secondary">{{ $label }}</div>
                    <div class="font-display text-[26px] font-bold leading-tight" style="color: {{ $tone }};">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        {{-- The check-in flow, with the app's real status pills. --}}
        <div class="mt-5 flex flex-col divide-y divide-row">
            @foreach ([
                ['Brenda Miles', __('Hair cut · 9:00 · Maya'), __('Completed'), '#E7EFE4', '#3E5C3A'],
                ['Megan Wu', __('Nails · 12:00 · Elise'), __('In service'), '#FBEFD6', '#8A5A1E'],
                ['Amy Jenner', __('Blowout · 1:00 · Sofia'), __('Arrived'), '#E3EDF6', '#356088'],
                ['James Holt', __('Cut · 3:00 · Jonah'), __('Booked'), '#F0EEEA', '#56534C'],
            ] as [$client, $meta, $pill, $pillBg, $pillInk])
                <div class="flex items-center gap-3 py-2.5">
                    <div>
                        <div class="text-[13.5px] font-semibold text-ink">{{ $client }}</div>
                        <div class="text-[12px] text-secondary">{{ $meta }}</div>
                    </div>
                    <div class="flex-1"></div>
                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold" style="background-color: {{ $pillBg }}; color: {{ $pillInk }};">{{ $pill }}</span>
                </div>
            @endforeach
        </div>

        {{-- Where today's bookings came from. --}}
        <div class="mt-5 border-t border-divider pt-4">
            <div class="text-[10.5px] font-semibold uppercase tracking-[0.06em] text-secondary">{{ __('Where bookings came from') }}</div>
            <div class="mt-2.5 flex flex-col gap-2">
                @foreach ([[__('Voice AI'), 42, true], [__('Booking widget'), 33, true], [__('Front desk'), 25, false]] as [$source, $share, $isAi])
                    <div class="flex items-center gap-3 text-[12px]">
                        <span class="w-24 shrink-0 {{ $isAi ? 'font-semibold text-accent-ink' : 'text-secondary' }}">{{ $source }}</span>
                        <div class="h-2 flex-1 overflow-hidden rounded-[99px] bg-muted">
                            <div class="h-full rounded-[99px] {{ $isAi ? 'bg-accent' : 'bg-secondary' }}" style="width: {{ $share }}%"></div>
                        </div>
                        <span class="w-9 shrink-0 text-right text-secondary">{{ $share }}%</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- App showcase: the embeddable booking widget — the split branded shell
     (logo + running visit summary beside the inline availability calendar),
     exactly the product's default look. Static mockup. --}}
<div class="overflow-hidden rounded-[20px] border border-border" style="background-color: #F7F4EF; box-shadow: 0 24px 60px rgba(28,27,26,.10);">
    <div class="grid sm:grid-cols-[180px_minmax(0,1fr)]">
        <div class="border-b p-4 sm:border-b-0 sm:border-e" style="border-color: rgba(28,27,26,.12);">
            <div class="text-[10.5px] font-semibold uppercase tracking-[0.1em]" style="color: #6B3358;">{{ __('Book an appointment') }}</div>
            <div class="font-display mt-1 text-[16px] font-bold" style="color: #1C1B1A;">Glamour Studio</div>
            <div class="mt-3 flex flex-col gap-1.5 text-[11.5px]" style="color: rgba(28,27,26,.68);">
                <div class="flex justify-between"><span>{{ __('Hair cut') }}</span><span>10:00 · Maya</span></div>
                <div class="flex justify-between border-t pt-1.5" style="border-color: rgba(28,27,26,.12);"><span>{{ __('Nails') }}</span><span>2:00 · Elise</span></div>
                <div class="flex justify-between border-t pt-1.5 font-semibold" style="border-color: rgba(28,27,26,.12); color: #1C1B1A;"><span>{{ __('Total') }}</span><span>105 {{ __('min') }} · $100</span></div>
            </div>
        </div>
        <div class="p-4">
            <div class="font-display text-[14px] font-bold" style="color: #1C1B1A;">{{ __('Select date & time') }}</div>
            <div class="mt-2.5 grid grid-cols-7 gap-1 text-center text-[10.5px]">
                @foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $d)
                    <span class="font-semibold uppercase" style="color: rgba(28,27,26,.38);">{{ $d }}</span>
                @endforeach
                @foreach (range(6, 19) as $d)
                    @php($state = in_array($d, [9, 10, 12, 16, 17], true) ? 'open' : ($d === 11 ? 'picked' : 'off'))
                    <span class="flex h-7 items-center justify-center rounded-full text-[11px] {{ $state === 'open' || $state === 'picked' ? 'font-semibold' : '' }}"
                          style="{{ match($state) {
                              'picked' => 'background-color:#824C71;color:#fff;',
                              'open' => 'background-color:rgba(130,76,113,.16);color:#1C1B1A;',
                              default => 'color:rgba(28,27,26,.38);',
                          } }}">{{ $d }}</span>
                @endforeach
            </div>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach (['9:00 AM', '10:00 AM', '11:30 AM', '2:00 PM'] as $slot)
                    <span class="rounded-[10px] border px-2.5 py-1.5 text-[11px] font-semibold" style="border-color: rgba(130,76,113,.7); color: #1C1B1A;">{{ $slot }}</span>
                @endforeach
            </div>
        </div>
    </div>
</div>

@props([
    'card',
    'selected' => false,
    'badge' => null,
])

{{--
    One clickable staff card on the availability screen: avatar, name, and a
    one-line weekly summary. Clicking opens the right-side schedule panel via
    the parent component's openPanel(). List-card tokens: radius 18, card
    border/shadow, accent ring when its panel is open.
--}}
<button type="button" wire:click="openPanel({{ $card['id'] }})" id="availability-card-{{ $card['id'] }}"
        class="flex items-center gap-4 rounded-[18px] border bg-card p-5 text-left shadow-xs transition
               {{ $selected ? 'border-accent ring-1 ring-accent' : 'border-border hover:border-accent/60' }}"
        aria-label="{{ __(':name — view schedule', ['name' => $card['name']]) }}">
    <x-ui.avatar :name="$card['name']" :seed="$card['id']" size="lg" />
    <span class="flex min-w-0 flex-1 flex-col">
        <span class="flex items-center gap-2">
            <span class="truncate text-[16px] font-semibold text-ink">{{ $card['name'] }}</span>
            @if ($badge)
                <span class="bts-pill shrink-0" style="background-color: var(--accent-tint); color: var(--accent-ink);">{{ $badge }}</span>
            @endif
        </span>
        <span class="truncate text-[14px] text-secondary">{{ $card['summary'] }}</span>
    </span>
    <flux:icon.chevron-right variant="mini" class="shrink-0 text-fainter" />
</button>

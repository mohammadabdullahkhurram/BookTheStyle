{{-- The inline outcome panel of one integration check — shared by every
     "Test"/"Verify" control (partials.integration-check) and by the GHL
     connection card, so results look identical everywhere. Expects:
       $result      array{state, message, hint, details, at}|null (stored shape)
       $blocked     bool   — needs-public-URL state (renders a note, no result)
       $blockedNote string — the explanation for the blocked state
     Pass/fail is never colour-alone: icon + "Passed"/"Failed" word carry it.
     The aria-live region is always present so screen readers hear updates. --}}
@php
    $result = $result ?? null;
    $blocked = $blocked ?? false;
    $passed = is_array($result) && ($result['state'] ?? null) === 'passed';
@endphp

<div aria-live="polite">
    @if ($blocked)
        <div class="flex items-start gap-2.5 rounded-[11px] border border-input-border bg-field px-4 py-3">
            <flux:icon.information-circle variant="micro" class="mt-0.5 shrink-0 text-faint" />
            <p class="text-[13.5px] leading-relaxed text-secondary">
                {{ $blockedNote ?? __('This check needs the app’s live public URL — it runs automatically once the app is deployed.') }}
            </p>
        </div>
    @elseif (is_array($result))
        <div class="flex flex-col gap-2 rounded-[11px] border px-4 py-3"
             style="background-color: {{ $passed ? '#E7EFE4' : '#F8E3E3' }}; border-color: {{ $passed ? '#D8E4D5' : '#F0D6D6' }};">
            <p class="flex items-start gap-2 text-[13.5px] font-medium leading-relaxed" style="color: {{ $passed ? '#3E5C3A' : '#A23A3A' }};">
                @if ($passed)
                    <flux:icon.check-circle variant="micro" class="mt-0.5 shrink-0" />
                @else
                    <flux:icon.x-circle variant="micro" class="mt-0.5 shrink-0" />
                @endif
                <span><span class="font-semibold">{{ $passed ? __('Passed') : __('Failed') }}</span> — {{ $result['message'] }}</span>
            </p>

            @if (($result['details'] ?? []) !== [])
                <ul class="flex flex-col gap-1 ps-6">
                    @foreach ($result['details'] as $detail)
                        <li class="flex items-start gap-1.5 text-[13px] leading-relaxed" style="color: {{ $detail['ok'] ? '#3E5C3A' : '#A23A3A' }};">
                            @if ($detail['ok'])
                                <flux:icon.check variant="micro" class="mt-0.5 shrink-0" />
                            @else
                                <flux:icon.x-mark variant="micro" class="mt-0.5 shrink-0" />
                            @endif
                            <span>{{ $detail['text'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (! $passed && filled($result['hint'] ?? null))
                <p class="ps-6 text-[13px] leading-relaxed text-body">{{ __('Likely fix:') }} {{ $result['hint'] }}</p>
            @endif
        </div>
    @endif
</div>

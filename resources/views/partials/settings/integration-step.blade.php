{{--
    One numbered step header for the guided Integrations setup.
    Inputs: n (int), title, lead (plain-language what/why), status
    ('done'|'attention'|'todo'). Renders above the step's card(s).
--}}
@php([$chipBg, $chipInk, $chipLabel] = match ($status) {
    'done' => ['#E7EFE4', '#3E5C3A', __('Done')],
    'attention' => ['#FBEFD6', '#8A5A1E', __('Needs attention')],
    default => ['#F0EEEA', '#6B6862', __('Not set up')],
})
<div class="mt-2 flex items-start gap-3 first:mt-0">
    <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full text-[13px] font-semibold {{ $status === 'done' ? 'text-white' : 'text-secondary' }}"
          style="background-color:{{ $status === 'done' ? 'var(--accent)' : '#EAE6DF' }};" aria-hidden="true">{{ $n }}</span>
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2.5">
            <h2 class="font-display text-[17px] font-semibold text-ink">{{ __('Step :n — :title', ['n' => $n, 'title' => $title]) }}</h2>
            <span class="bts-pill" style="background-color:{{ $chipBg }};color:{{ $chipInk }};">{{ $chipLabel }}</span>
        </div>
        <p class="mt-0.5 text-[13.5px] leading-relaxed text-secondary">{{ $lead }}</p>
    </div>
</div>

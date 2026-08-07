<x-mail::message>
# {{ __('Hello :name,', ['name' => $name]) }}

{{ trans_choice(
    'A health check for :salon that was passing has started failing:|:count health checks for :salon that were passing have started failing:',
    count($regressions),
    ['salon' => $salon->name, 'count' => count($regressions)],
) }}

<x-mail::panel>
@foreach ($regressions as $regression)
**{{ $regression['label'] }}** — {{ $regression['message'] }}
@if (! $loop->last)

@endif
@endforeach
</x-mail::panel>

{{ __('This came from the automatic monitor (or a manual run) comparing against the previous check. Open the health-check page for the full report and the suggested fixes:') }}

<x-mail::button :url="$healthUrl">
{{ __('Open the health check') }}
</x-mail::button>

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>

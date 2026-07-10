<x-mail::message>
# {{ __('Hello :name,', ['name' => $name]) }}

{{ __('A new salon, :salon, was just added under :agency.', ['salon' => $salonName, 'agency' => $agencyName]) }}

{{ __('You can review its settings, connect GoHighLevel, and invite staff from the salon dashboard.') }}

<x-mail::button :url="$salonUrl">
{{ __('Open :salon', ['salon' => $salonName]) }}
</x-mail::button>

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>

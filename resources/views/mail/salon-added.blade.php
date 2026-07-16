<x-mail::message>
# {{ __('Hello :name,', ['name' => $name]) }}

{{ __('A new salon, :salon, was just created under :agency. Here is everything captured at creation:', ['salon' => $salon->name, 'agency' => $agencyName]) }}

<x-mail::panel>
**{{ __('Salon') }}:** {{ $salon->name }}@if ($salon->legal_business_name !== '' && $salon->legal_business_name !== $salon->name) ({{ $salon->legal_business_name }})@endif<br>
**{{ __('Web address') }}:** {{ $salonUrl }}<br>
**{{ __('Timezone') }}:** {{ $salon->timezone }} · **{{ __('Currency') }}:** {{ $salon->currency }}
@if ($salon->contact_name !== '' || $salon->contact_email !== '' || $salon->contact_phone !== '')
<br>**{{ __('Contact') }}:** {{ trim($salon->contact_name.' — '.$salon->contact_email.' '.$salon->contact_phone, ' —') }}
@endif
@if ($salon->business_email !== '' || $salon->business_phone !== '')
<br>**{{ __('Business') }}:** {{ trim($salon->business_email.' '.$salon->business_phone) }}
@endif
@if ($salon->address_line1 !== '')
<br>**{{ __('Address') }}:** {{ trim($salon->address_line1.', '.$salon->city.' '.$salon->region.' '.$salon->postal_code, ', ') }}
@endif
</x-mail::panel>

{{ __('Next steps:') }}

1. {{ __('Open the setup wizard — it tracks staff, services, availability and the GoHighLevel steps, and verifies each one.') }}
2. {{ __('Connect GoHighLevel (the wizard shows the exact scopes the Private Integration Token needs).') }}
3. {{ __('Invite the salon\'s staff — each receives a one-time temporary password.') }}

<x-mail::button :url="$setupUrl">
{{ __('Open the setup wizard') }}
</x-mail::button>

{{ __('Or go straight to the salon:') }} [{{ $salonUrl }}]({{ $salonUrl }})

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>

<x-mail::message>
# {{ __('Hello :name,', ['name' => $name]) }}

{{ __('You have been invited to join :salon on :app as :role.', ['salon' => $salonName, 'app' => config('app.name'), 'role' => $roleLabel]) }}

@if ($temporaryPassword)
{{ __('Use the temporary password below to sign in for the first time — you will be asked to choose your own password right away.') }}

<x-mail::panel>
{{-- Raw HTML block: markdown must never touch the password (paired * or _ would render as emphasis and drop characters). --}}
<div style="font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 16px; letter-spacing: 1px;">{{ $temporaryPassword }}</div>
</x-mail::panel>
@else
{{ __('Your existing :app login now has access to this salon.', ['app' => config('app.name')]) }}
@endif

<x-mail::button :url="$loginUrl">
{{ __('Sign in') }}
</x-mail::button>

{{ __('If you were not expecting this, you can ignore this email.') }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>

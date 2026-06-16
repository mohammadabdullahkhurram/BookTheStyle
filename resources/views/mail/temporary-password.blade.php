<x-mail::message>
# {{ __('Hello :name,', ['name' => $name]) }}

@if ($reason === 'reset')
{{ __('An administrator has reset your :app password. Use the temporary password below to sign in, then choose a new one.', ['app' => config('app.name')]) }}
@else
{{ __('An account has been created for you on :app. Use the temporary password below to sign in for the first time, then choose a new one.', ['app' => config('app.name')]) }}
@endif

<x-mail::panel>
{{ $temporaryPassword }}
</x-mail::panel>

{{ __('You will be asked to set a new password the first time you sign in.') }}

<x-mail::button :url="$loginUrl">
{{ __('Sign in') }}
</x-mail::button>

{{ __('If you were not expecting this, you can ignore this email.') }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>

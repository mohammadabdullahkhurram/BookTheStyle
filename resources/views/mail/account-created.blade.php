<x-mail::message>
# {{ __('Hello :name,', ['name' => $name]) }}

{{ __('An account has been created for you on :app for :workplace.', ['app' => config('app.name'), 'workplace' => $workplace]) }}

{{ __('Your sign-in details arrive in a separate email. Once you have them, sign in below — you will be asked to choose your own password the first time.') }}

<x-mail::button :url="$loginUrl">
{{ __('Sign in') }}
</x-mail::button>

{{ __('If you were not expecting this, you can ignore this email.') }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>

<x-layouts::auth :title="__('Set a new password')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Set a new password')"
            :description="__('You are signed in with a temporary password. Choose a new one to continue.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.change.update') }}" class="flex flex-col gap-6" novalidate>
            @csrf
            @method('PUT')

            <flux:input
                name="current_password"
                :label="__('Temporary password')"
                type="password"
                required
                autofocus
                autocomplete="current-password"
                :placeholder="__('Temporary password')"
                viewable
            />

            <flux:input
                name="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('New password')"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirm new password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm new password')"
                viewable
            />

            <x-ui.button type="submit" class="w-full" data-test="update-password-button">{{ __('Set password & continue') }}</x-ui.button>

            <flux:text class="text-center text-sm text-secondary">
                {{ __('Lost your temporary password? Log out and use "Forgot password" on the sign-in screen — completing a reset also replaces it. Or ask your administrator to issue a new one.') }}
            </flux:text>
        </form>
    </div>
</x-layouts::auth>

<x-layouts::auth :title="__('Set a new password')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Set a new password')"
            :description="__('You are signed in with a temporary password. Choose a new one to continue.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.change.update') }}" class="flex flex-col gap-6">
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

            <flux:button variant="primary" type="submit" class="w-full" data-test="update-password-button">
                {{ __('Update password') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>

<x-layouts::auth :title="__('Forgot password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6" novalidate>
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
            />

            <x-ui.button type="submit" class="w-full" data-test="email-password-reset-link-button">{{ __('Email password reset link') }}</x-ui.button>
        </form>

        <p class="text-center text-[14px] text-secondary">
            {{ __('Or, return to') }}
            <a href="{{ route('login') }}" wire:navigate class="font-semibold text-accent transition hover:text-accent-hover">{{ __('log in') }}</a>
        </p>
    </div>
</x-layouts::auth>

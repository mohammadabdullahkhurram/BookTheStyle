<div class="flex items-start gap-8 max-md:flex-col">
    <div class="w-full md:w-[210px] md:shrink-0">
        <nav class="flex gap-1 md:flex-col" aria-label="{{ __('Settings') }}">
            <a href="{{ route('profile.edit') }}" wire:navigate
               class="bts-nav-item {{ request()->routeIs('profile.*') ? 'bts-nav-item-active' : '' }}">{{ __('Profile') }}</a>
            <a href="{{ route('security.edit') }}" wire:navigate
               class="bts-nav-item {{ request()->routeIs('security.*') ? 'bts-nav-item-active' : '' }}">{{ __('Security') }}</a>
        </nav>
    </div>

    <div class="min-w-0 flex-1">
        <h2 class="bts-card-title">{{ $heading ?? '' }}</h2>
        <p class="mt-1 text-[15px] text-secondary">{{ $subheading ?? '' }}</p>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>

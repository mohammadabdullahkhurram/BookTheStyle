{{-- Business details + address (shared). Host component exposes the profile
     string properties and validates with App\Support\SalonProfile::rules(). --}}
@php($countries = \App\Support\SalonProfile::countries())

<div class="flex flex-col gap-5">
    <flux:separator :text="__('Business details')" />

    <flux:input wire:model.blur="name" :label="__('Business / trading name')"
        :description="__('The salon\'s public name (also the GoHighLevel sub-account name).')" required />

    <flux:input wire:model="legal_business_name" :label="__('Legal business name')"
        :description="__('Registered entity name, if different from the trading name.')" required />

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:input type="email" wire:model="business_email" :label="__('Business email')" required />
        <flux:input type="tel" wire:model="business_phone" :label="__('Business phone')" required />
    </div>

    <flux:input type="url" wire:model="website" :label="__('Website')"
        :description="__('Optional. Include https://')" placeholder="https://example.com" />

    <flux:separator :text="__('Address')" />

    <flux:input wire:model="address_line1" :label="__('Address line 1')" required />
    <flux:input wire:model="address_line2" :label="__('Address line 2')" :description="__('Optional.')" />

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:input wire:model="city" :label="__('City')" required />
        <flux:input wire:model="region" :label="__('State / province / region')" required />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:input wire:model="postal_code" :label="__('Postal code')" required />
        <flux:select wire:model="country" :label="__('Country')">
            <flux:select.option value="">{{ __('Select a country') }}</flux:select.option>
            {{-- Keep any stored value selectable even if it is not in the list. --}}
            @if ($country !== '' && ! in_array($country, $countries, true))
                <flux:select.option value="{{ $country }}">{{ $country }}</flux:select.option>
            @endif
            @foreach ($countries as $countryOption)
                <flux:select.option value="{{ $countryOption }}">{{ $countryOption }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>
</div>

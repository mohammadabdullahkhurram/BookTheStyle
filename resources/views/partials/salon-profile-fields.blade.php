{{-- Shared "Business details / Address / Owner details" sections for the
     agency salon create + edit screens and salon settings. The host Livewire
     component must expose these string properties (all profile columns) and
     validate them with App\Support\SalonProfile::rules(). `name` is the business
     / trading name. Only website + address_line2 are optional. --}}
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

    <flux:separator :text="__('Owner details')" />

    <flux:input wire:model="contact_name" :label="__('Owner name')"
        :description="__('This person is the salon\'s OWNER — a user account with the owner role is created for them when the salon is created.')" required />

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:input type="email" wire:model="contact_email" :label="__('Owner email')" required />
        <flux:input type="tel" wire:model="contact_phone" :label="__('Owner phone')" required />
    </div>
</div>

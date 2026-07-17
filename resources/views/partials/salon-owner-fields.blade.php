{{-- Owner details (shared): the salon's OWNER — the single source of truth
     reconciled on save (ReconcileSalonOwner) — plus the owner-as-stylist
     bookability checkbox. --}}
<div class="flex flex-col gap-5">
    <flux:separator :text="__('Owner details')" />

    <flux:input wire:model="contact_name" :label="__('Owner name')"
        :description="__('This person is the salon\'s OWNER — a user account with the owner role is created for them when the salon is created.')" required />

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:input type="email" wire:model="contact_email" :label="__('Owner email')" required />
        <flux:input type="tel" wire:model="contact_phone" :label="__('Owner phone')" required />
    </div>

    <div class="flex flex-col gap-1">
        <flux:checkbox wire:model="owner_is_stylist" :label="__('The owner is also a stylist')" />
        <p class="ps-6 text-[12.5px] leading-relaxed text-secondary">{{ __('They become bookable — availability, a calendar column, appointments — while keeping full owner rights.') }}</p>
    </div>
</div>

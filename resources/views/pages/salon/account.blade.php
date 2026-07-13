<?php

use App\Models\Salon;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * My calendar — the salon-side home of the PERSONAL calendar feed. Every
 * salon member (stylist, front desk, managers) manages their OWN private
 * read-only iCal subscription here: the feed carries that user's bookings
 * only, one-way, token-authorized. Membership is enforced by ResolveSalon;
 * no manage gate — this page is personal, not salon administration.
 */
new #[Title('My calendar')] class extends Component {
    public Salon $salon;

    public function mount(Salon $salon): void
    {
        $this->salon = $salon;
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
    <x-ui.page-header :overline="__('My account')" :title="__('My calendar')">
        <x-slot:subtitle>{{ __('Your bookings on your own Google, Apple, or Outlook calendar.') }}</x-slot:subtitle>
    </x-ui.page-header>

    <livewire:pages::settings.calendar-feed />
</div>

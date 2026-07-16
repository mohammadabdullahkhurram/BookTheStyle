<?php

use App\Actions\Stylists\UpdateStylistProfile;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\StylistProfile;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    /**
     * The signed-in user's stylist bio per salon they style at. Bio is stored
     * per (user, salon) on StylistProfile — the same field the Staff edit screen
     * and slot/availability features use; this just lets the stylist self-edit.
     *
     * @var array<int, array{name: string, bio: string}>
     */
    public array $stylistSalons = [];

    public function mount(): void
    {
        $user = Auth::user();

        $memberships = $user->salonMemberships()
            ->where('staff_type', StaffType::Stylist->value)
            ->where('active', true)
            ->with('salon:id,name')
            ->get();

        foreach ($memberships as $membership) {
            if ($membership->salon === null) {
                continue;
            }

            $bio = StylistProfile::query()
                ->where('salon_id', $membership->salon_id)
                ->where('user_id', $user->id)
                ->value('bio');

            $this->stylistSalons[$membership->salon_id] = [
                'name' => $membership->salon->name,
                'bio' => (string) $bio,
            ];
        }
    }

    public function saveBio(int $salonId, UpdateStylistProfile $action): void
    {
        // Only salons the user actually styles at are editable here.
        abort_unless(isset($this->stylistSalons[$salonId]), 403);

        $this->validate(["stylistSalons.{$salonId}.bio" => ['nullable', 'string', 'max:2000']]);

        $salon = Salon::findOrFail($salonId);

        $action->handle(Auth::user(), $salon, (int) Auth::id(), $this->stylistSalons[$salonId]['bio'] ?: null);

        Flux::toast(variant: 'success', text: __('Bio saved.'));
    }
}; ?>

<div>
    @if ($stylistSalons !== [])
        <x-ui.card class="mt-10 flex flex-col gap-5">
            <div>
                <h2 class="bts-card-title">{{ __('Stylist bio') }}</h2>
                <p class="mt-1 text-[14px] text-secondary">{{ __('A short introduction shown alongside your bookings. Visible to your salon.') }}</p>
            </div>

            @foreach ($stylistSalons as $salonId => $entry)
                <form wire:submit="saveBio({{ $salonId }})" class="flex flex-col gap-3" wire:key="bio-{{ $salonId }}" novalidate>
                    @if (count($stylistSalons) > 1)
                        <flux:label>{{ $entry['name'] }}</flux:label>
                    @endif
                    <flux:textarea wire:model="stylistSalons.{{ $salonId }}.bio" rows="3" :placeholder="__('A short bio.')" />
                    <div><x-ui.button type="submit" size="sm">{{ __('Save bio') }}</x-ui.button></div>
                </form>
            @endforeach
        </x-ui.card>
    @endif
</div>

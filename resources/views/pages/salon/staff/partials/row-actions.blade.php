{{--
    Row actions for a salon membership. Edit is the promoted, benign action;
    everything else lives behind an overflow menu so destructive options never
    compete with it (Delete styled danger, behind the themed confirm). Rows
    the viewer lacks authority over show NO affordance — the server 403s
    regardless. The owner's own row carries only their bookability switch.
    Component-tag attributes stay single-line + single-quoted (Blade tag
    compiler rules — see CLAUDE.md).
--}}
@if ($canManage)
    <button type="button" wire:click="startEdit({{ $m->id }})" class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</button>
    <flux:dropdown position="bottom" align="end">
        <button type="button" class="rounded-[8px] border border-input-border p-1.5 text-secondary transition hover:text-ink" aria-label="{{ __('More actions for :name', ['name' => $m->user->name]) }}">
            <flux:icon.ellipsis-horizontal variant="micro" />
        </button>
        <flux:menu>
            <flux:menu.item icon="key" x-on:click="$store.confirm.ask({ title: {{ Js::from(__('Reset password')) }}, message: {{ Js::from(__('Reset this password? The current one stops working immediately and a new temporary password is shown once.')) }}, confirmLabel: {{ Js::from(__('Reset')) }}, danger: false }, () => $wire.resetPassword({{ $m->id }}))">{{ __('Reset password') }}</flux:menu.item>
            @if ($m->active)
                <flux:menu.item icon="pause-circle" x-on:click="$store.confirm.ask({ title: {{ Js::from(__('Deactivate member')) }}, message: {{ Js::from(__('Deactivate this member? They lose access to this salon; their bookings and history are kept.')) }}, confirmLabel: {{ Js::from(__('Deactivate')) }}, danger: true }, () => $wire.toggleActive({{ $m->id }}))">{{ __('Deactivate') }}</flux:menu.item>
            @else
                <flux:menu.item icon="play-circle" wire:click="toggleActive({{ $m->id }})">{{ __('Reactivate') }}</flux:menu.item>
            @endif
            @if ($m->user_id !== Auth::id())
                <flux:menu.separator />
                <flux:menu.item icon="trash" variant="danger" x-on:click="$store.confirm.ask({ title: {{ Js::from(__('Delete member')) }}, message: {{ Js::from(__('Removed from this salon permanently — and their account is deleted if this is their only access. Past bookings and history are kept under their name. Prefer Deactivate if they might return.')) }}, confirmLabel: {{ Js::from(__('Delete')) }}, danger: true }, () => $wire.deleteMember({{ $m->id }}))">{{ __('Delete') }}</flux:menu.item>
            @endif
        </flux:menu>
    </flux:dropdown>
@elseif ($m->user_id === Auth::id() && $m->salon_role === \App\Enums\SalonRole::Owner)
    {{-- The owner-who-cuts-hair switch: only the owner, on their own row. --}}
    <button type="button" wire:click="toggleOwnerBookable({{ $m->id }})" class="text-[13px] font-medium text-secondary transition hover:text-ink">{{ $m->staff_type === \App\Enums\StaffType::Stylist ? __('Stop taking bookings') : __('Take bookings') }}</button>
@else
    <span class="text-[13px] text-faint" aria-hidden="true">{{ __('—') }}</span>
@endif

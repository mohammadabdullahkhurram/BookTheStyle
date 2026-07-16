<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user. Server-enforced deletion
     * rule (SPEC §2): only salon OWNERS (and salon-less accounts) may
     * self-delete — salon admins/staff are salon-managed, and the agency
     * owner never may. The UI hides the section too; this is the guard.
     */
    public function deleteUser(Logout $logout): void
    {
        abort_unless(Auth::user()->canDeleteOwnAccount(), 403);

        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<x-ui.modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg"
    :heading="__('Are you sure you want to delete your account?')"
    :subheading="__('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.')">
    <form method="POST" wire:submit="deleteUser" class="space-y-6" novalidate>
        <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </div>
    </form>
</x-ui.modal>

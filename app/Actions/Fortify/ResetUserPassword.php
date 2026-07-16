<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // A completed reset IS the user choosing their own password — exactly
        // what must_change_password exists to force. Leaving the flag set
        // would trap them on the forced-change screen, which asks for the
        // temporary password the reset just replaced. Clear it.
        $user->forceFill([
            'password' => $input['password'],
            'must_change_password' => false,
        ])->save();
    }
}

<?php

use App\Actions\Staff\InviteStaff;
use App\Mail\StaffInviteMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\Salon;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/*
| The provisioning → first-login journey, walked end to end. Every path must
| terminate in a working session — a state a user cannot log in from is a
| lockout. Two production defects anchor these tests: (1) a password reset
| did not clear must_change_password, trapping the user on the forced-change
| screen which demands the temp password the reset had just replaced; and
| (2) markdown-significant characters in the temp password (* _) were eaten
| by the mail renderer, so the emailed password differed from the real one.
*/

function provisionStaff(): array
{
    $salon = Salon::factory()->create();
    $provisioned = app(InviteStaff::class)->handle(salonOwnerOf($salon), $salon, [
        'name' => 'Journey Tester',
        'email' => 'journey@example.com',
        'salon_role' => 'staff',
        'staff_type' => 'stylist',
    ]);

    return [$provisioned->user, $provisioned->temporaryPassword];
}

/** Complete Fortify's forgot-password flow and return the new password. */
function completePasswordReset(TestCase $test, User $user, string $newPassword = 'chosen-by-user-9'): string
{
    Notification::fake();
    $test->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($test, $user, $newPassword) {
        $test->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertSessionHasNoErrors();

        return true;
    });

    return $newPassword;
}

// ---------------------------------------------------------------------------
// Path 1: the happy path — temp login → forced change → in
// ---------------------------------------------------------------------------

it('walks temp-password login through the forced change into a working session', function () {
    Mail::fake();
    [$user, $temp] = provisionStaff();

    // Log in with the temp password; anything but the change screen bounces.
    $this->post(route('login'), ['email' => $user->email, 'password' => $temp]);
    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertRedirect(route('password.change'));

    // Set a real password: flag clears, dashboard opens.
    $this->put(route('password.change.update'), [
        'current_password' => $temp,
        'password' => 'chosen-by-user-9',
        'password_confirmation' => 'chosen-by-user-9',
    ])->assertRedirect(route('dashboard'));

    expect($user->fresh()->must_change_password)->toBeFalse();
    $this->get(route('dashboard'))->assertOk();
});

// ---------------------------------------------------------------------------
// Path 2 + 3: reset instead of first login / reset while flagged — the trap
// ---------------------------------------------------------------------------

it('releases a flagged user who completes a password reset — no forced-change trap', function () {
    Mail::fake();
    [$user, $temp] = provisionStaff();
    expect($user->must_change_password)->toBeTrue();

    // Never logged in; goes straight to "Forgot password" (the broken case).
    $newPassword = completePasswordReset($this, $user);

    // The reset satisfied the flag's whole intent: cleared.
    expect($user->fresh()->must_change_password)->toBeFalse();

    // …and they land in a working session, not on the forced-change screen.
    $this->post(route('login'), ['email' => $user->email, 'password' => $newPassword]);
    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertOk();
});

it('releases a user who logged in with the temp password first, then reset', function () {
    Mail::fake();
    [$user, $temp] = provisionStaff();

    // Saw the forced screen, gave up, logged out, used forgot-password.
    $this->post(route('login'), ['email' => $user->email, 'password' => $temp]);
    $this->post(route('logout'));

    $newPassword = completePasswordReset($this, $user);

    expect($user->fresh()->must_change_password)->toBeFalse();
    $this->post(route('login'), ['email' => $user->email, 'password' => $newPassword]);
    $this->get(route('dashboard'))->assertOk();
});

// ---------------------------------------------------------------------------
// Path 4: the temp password after a reset is dead — correctly, not a trap
// ---------------------------------------------------------------------------

it('rejects the superseded temp password after a reset, while the new one works', function () {
    Mail::fake();
    [$user, $temp] = provisionStaff();
    $newPassword = completePasswordReset($this, $user);

    $this->post(route('login'), ['email' => $user->email, 'password' => $temp])
        ->assertSessionHasErrors();
    $this->assertGuest();

    $this->post(route('login'), ['email' => $user->email, 'password' => $newPassword]);
    $this->assertAuthenticatedAs($user);
});

// ---------------------------------------------------------------------------
// Path 5: a week-old untouched invite still works (no silent expiry)
// ---------------------------------------------------------------------------

it('accepts a temp password from an invite ignored for a week', function () {
    Mail::fake();
    [$user, $temp] = provisionStaff();

    $this->travel(8)->days();

    $this->post(route('login'), ['email' => $user->email, 'password' => $temp]);
    $this->assertAuthenticatedAs($user);
});

// ---------------------------------------------------------------------------
// The temp password itself: plaintext ⇄ hash ⇄ email must all agree
// ---------------------------------------------------------------------------

it('proves the emailed plaintext matches the stored hash', function () {
    Mail::fake();
    [$user, $temp] = provisionStaff();

    // The returned/one-time-displayed plaintext verifies against the hash…
    expect(Hash::check($temp, $user->fresh()->password))->toBeTrue();

    // …and the invite email carries that exact plaintext, character for
    // character, in its rendered HTML.
    Mail::assertQueued(StaffInviteMail::class, function (StaffInviteMail $mail) use ($temp) {
        return $mail->temporaryPassword === $temp
            && str_contains($mail->render(), e($temp));
    });
});

it('renders a markdown-hostile password verbatim in every temp-password email', function () {
    // The regression: paired * became <em> and the characters vanished from
    // the email. Force the worst case through both mail views.
    $hostile = 'ab*cd*ef_gh_i-k9#l&m';

    $invite = (new StaffInviteMail('T', 'Salon', 'Staff', $hostile, route('login')))->render();
    expect($invite)->toContain(e($hostile));

    $user = User::factory()->make();
    foreach (['invite', 'reset'] as $reason) {
        $html = (new TemporaryPasswordMail($user, $hostile, $reason))->render();
        expect($html)->toContain(e($hostile));
    }
});

it('generates temp passwords that no renderer or email client can mangle', function () {
    foreach (range(1, 20) as $i) {
        $password = TemporaryPassword::generate();

        expect(strlen($password))->toBeGreaterThanOrEqual(20);
        expect(ctype_alnum($password))->toBeTrue($password);
    }
});

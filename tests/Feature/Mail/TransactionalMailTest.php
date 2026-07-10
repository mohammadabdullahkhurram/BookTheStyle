<?php

use App\Actions\AgencyUsers\CreateAgencyUser;
use App\Actions\Salons\CreateSalon;
use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\ResetStaffPassword;
use App\Enums\AgencyRole;
use App\Mail\AccountCreatedMail;
use App\Mail\SalonAddedMail;
use App\Mail\StaffInviteMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Mail;

/*
| Transactional emails, app-direct (never GHL — login-critical mail must not
| depend on a connection): account created, temporary password, password
| reset, staff invite, salon added. All queued markdown mailables in the
| BookTheStyle theme, fail-safe so a broken transport never locks anyone out.
*/

function mailAgency(): Agency
{
    return Agency::factory()->create(['name' => 'Bluejaypro']);
}

function mailAgencyOwnerOf(Agency $agency): User
{
    return User::factory()->create([
        'agency_id' => $agency->id,
        'agency_role' => AgencyRole::Owner,
    ]);
}

// ---------------------------------------------------------------------------
// Triggers → mailables
// ---------------------------------------------------------------------------

it('emails a welcome and a temporary password when an agency user is created', function () {
    Mail::fake();
    $agency = mailAgency();

    $result = app(CreateAgencyUser::class)->handle(mailAgencyOwnerOf($agency), $agency, [
        'name' => 'Ava Agency', 'email' => 'ava@example.com', 'agency_role' => 'agency_user',
    ]);

    Mail::assertQueued(AccountCreatedMail::class, fn ($mail) => $mail->hasTo('ava@example.com')
        && $mail->workplaceName === 'Bluejaypro');
    Mail::assertQueued(TemporaryPasswordMail::class, fn ($mail) => $mail->hasTo('ava@example.com')
        && $mail->temporaryPassword === $result->temporaryPassword);
});

it('emails a welcome and a credentialed invite to NEW salon staff, invite-only to existing staff', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $new = app(InviteStaff::class)->handle($owner, $salon, [
        'name' => 'Nina New', 'email' => 'nina@example.com', 'salon_role' => 'user', 'staff_type' => 'stylist',
    ]);

    Mail::assertQueued(AccountCreatedMail::class, fn ($mail) => $mail->hasTo('nina@example.com'));
    Mail::assertQueued(StaffInviteMail::class, fn ($mail) => $mail->hasTo('nina@example.com')
        && $mail->salonName === $salon->name
        && $mail->temporaryPassword === $new->temporaryPassword);

    // An existing login added to another salon: invite only, no credentials.
    $other = Salon::factory()->create();
    app(InviteStaff::class)->handle(salonOwnerOf($other), $other, [
        'name' => 'Nina New', 'email' => 'nina@example.com', 'salon_role' => 'user', 'staff_type' => 'stylist',
    ]);

    Mail::assertQueued(StaffInviteMail::class, fn ($mail) => $mail->salonName === $other->name
        && $mail->temporaryPassword === null);
    Mail::assertQueued(AccountCreatedMail::class, 1); // still just the first one
});

it('emails a reset temporary password from the admin reset action', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    $membership = $salon->memberships()->where('user_id', $stylist->id)->first();

    $plain = app(ResetStaffPassword::class)->handle($owner, $salon, $membership);

    Mail::assertQueued(TemporaryPasswordMail::class, fn ($mail) => $mail->hasTo($stylist->email)
        && $mail->reason === 'reset'
        && $mail->temporaryPassword === $plain);
});

it('notifies agency owners and admins — not agency users — when a salon is added', function () {
    Mail::fake();
    $agency = mailAgency();
    $owner = mailAgencyOwnerOf($agency);
    $admin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);
    $plainUser = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);

    app(CreateSalon::class)->handle($agency, salonProfileInput([
        'slug' => 'mail-added', 'timezone' => 'America/New_York',
    ]));

    Mail::assertQueued(SalonAddedMail::class, fn ($mail) => $mail->hasTo($owner->email) && $mail->salonName === 'Glow Bar');
    Mail::assertQueued(SalonAddedMail::class, fn ($mail) => $mail->hasTo($admin->email));
    Mail::assertNotQueued(SalonAddedMail::class, fn ($mail) => $mail->hasTo($plainUser->email));
});

// ---------------------------------------------------------------------------
// Rendering — branded HTML + plain-text alternative
// ---------------------------------------------------------------------------

it('renders every mailable with its key content and a plain-text alternative', function () {
    $mailables = [
        [new AccountCreatedMail('Nina New', 'Glow Bar', 'https://example.test/login'), ['Nina New', 'Glow Bar'], 'mail.account-created', ['name' => 'Nina New', 'workplace' => 'Glow Bar', 'loginUrl' => 'https://x.test']],
        [new StaffInviteMail('Nina New', 'Glow Bar', 'Staff', 'tmp-Secret123', 'https://example.test/login'), ['Nina New', 'Glow Bar', 'tmp-Secret123'], 'mail.staff-invite', ['name' => 'N', 'salonName' => 'G', 'roleLabel' => 'Staff', 'temporaryPassword' => 'tmp-Secret123', 'loginUrl' => 'https://x.test']],
        [new SalonAddedMail('Ava Agency', 'Glow Bar', 'Bluejaypro', 'https://example.test/salon'), ['Ava Agency', 'Glow Bar', 'Bluejaypro'], 'mail.salon-added', ['name' => 'A', 'salonName' => 'G', 'agencyName' => 'B', 'salonUrl' => 'https://x.test']],
        [new TemporaryPasswordMail(User::factory()->create(), 'tmp-Secret456', 'invite'), ['tmp-Secret456'], null, []],
    ];

    foreach ($mailables as [$mailable, $expected, $textView, $textData]) {
        $html = $mailable->render();

        foreach ($expected as $needle) {
            expect($html)->toContain($needle);
        }

        // Branded theme: the violet primary button.
        expect($html)->toContain('#6555e4');

        // The markdown mailer emits a plain-text alternative from the same view.
        if ($textView !== null) {
            $text = (string) app(Markdown::class)->renderText($textView, $textData);
            expect(trim($text))->not->toBe('');
        }
    }
});

it('builds a branded, queued password-reset notification with the reset link', function () {
    $user = User::factory()->create();

    $notification = new ResetPasswordNotification('fake-token');
    $message = $notification->toMail($user);

    expect($notification)->toBeInstanceOf(ShouldQueue::class);
    expect($message->subject)->toContain('Reset your');
    expect($message->actionUrl)->toContain('fake-token');
    expect($message->actionText)->toBe('Reset password');
});

// ---------------------------------------------------------------------------
// Fail-safe — mail down must never lock anyone out
// ---------------------------------------------------------------------------

it('still provisions the account and surfaces the temp password when mail is down', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $result = app(InviteStaff::class)->handle($owner, $salon, [
        'name' => 'Lock Out', 'email' => 'lockout@example.com', 'salon_role' => 'user', 'staff_type' => 'stylist',
    ]);

    // The user exists and the plaintext is still returned for in-app display.
    expect($result->temporaryPassword)->not->toBeNull();
    expect(User::where('email', 'lockout@example.com')->exists())->toBeTrue();
});

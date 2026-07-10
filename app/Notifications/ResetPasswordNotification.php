<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Branded password-reset email (login-critical, app-direct — never GHL).
 * Extends Laravel's stock notification, so Fortify's broker, token and URL
 * handling are untouched; only the rendered message changes (BookTheStyle
 * markdown theme + copy) and delivery is queued.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Reset your :app password', ['app' => config('app.name')]))
            ->greeting(__('Hello,'))
            ->line(__('We received a request to reset the password for your :app account.', ['app' => config('app.name')]))
            ->action(__('Reset password'), $url)
            ->line(__('This link expires in :count minutes.', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->line(__('If you did not request a password reset, no action is needed — your password is unchanged.'))
            ->salutation(__('Thanks,')."\n".config('app.name'));
    }
}

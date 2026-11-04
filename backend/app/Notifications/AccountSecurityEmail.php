<?php

namespace App\Notifications;

use App\Enums\AccountChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the owner their password or email was changed, so a change they did not
 * make is noticed. Always sent: it cannot be switched off in email settings.
 */
class AccountSecurityEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  string  $name  Greeting name: a notice to an old email address has no user to read it from.
     * @param  string|null  $newEmail  For an email change, the address it was changed to.
     */
    public function __construct(
        public readonly AccountChange $change,
        public readonly string $name,
        public readonly ?string $newEmail = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $lines] = match ($this->change) {
            AccountChange::PasswordChanged => ['Your LiveBid password was changed', [
                'The password for your LiveBid account was just changed. Other devices were signed out.',
            ]],
            AccountChange::PasswordReset => ['Your LiveBid password was reset', [
                'The password for your LiveBid account was just reset with a link sent to this address. Every device was signed out.',
            ]],
            AccountChange::EmailChanged => ['The email on your LiveBid account was changed', [
                "Your LiveBid account now uses **{$this->newEmail}** to log in. We sent this notice to your old address.",
            ]],
        };

        // After an email change a reset link would go to the new address, so that notice
        // cannot offer one: the old address can only raise the alarm.
        $canReset = $this->change !== AccountChange::EmailChanged;

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.account', [
                'name' => $this->name,
                'heading' => $subject,
                'lines' => [...$lines, 'If this was you, there is nothing more to do.'],
                'buttonLabel' => 'Reset your password',
                'buttonUrl' => $canReset ? config('app.frontend_url').'/forgot-password' : null,
                'footnote' => $canReset
                    ? 'If you did not make this change, reset your password now with the button above.'
                    : 'If you did not make this change, contact LiveBid support right away so we can secure your account.',
            ]);
    }
}

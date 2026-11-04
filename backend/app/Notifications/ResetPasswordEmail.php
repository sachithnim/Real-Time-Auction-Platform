<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The "Forgot password?" link. It opens the web app's reset page, which posts
 * the token back to the API. Queued like every other email.
 */
class ResetPasswordEmail extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function toMail($notifiable): MailMessage
    {
        /** @var User $notifiable */
        $minutes = (int) config('auth.passwords.users.expire');
        $url = config('app.frontend_url').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your LiveBid password')
            ->markdown('mail.account', [
                'name' => $notifiable->first_name ?: $notifiable->name,
                'heading' => 'Reset your password',
                'lines' => [
                    'We received a request to reset the password for your LiveBid account.',
                    "The link works once and expires in {$minutes} minutes.",
                ],
                'buttonLabel' => 'Choose a new password',
                'buttonUrl' => $url,
                'footnote' => 'If you did not ask for this, you can ignore this email: your password stays the same.',
            ]);
    }
}

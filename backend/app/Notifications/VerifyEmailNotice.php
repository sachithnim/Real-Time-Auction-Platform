<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * "Confirm your email": sent after sign-up and after an email change. The link
 * works without logging in (it is signed, and names the address it was sent to),
 * so it can be opened on another device.
 */
class VerifyEmailNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** How long the link works. */
    public const EXPIRE_HOURS = 24;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function url(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHours(self::EXPIRE_HOURS), [
            'user' => $user->getKey(),
            // Ties the link to this address: after another email change it no longer verifies.
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email for LiveBid')
            ->markdown('mail.account', [
                'name' => $notifiable->first_name ?: $notifiable->name,
                'heading' => 'Confirm your email',
                'lines' => [
                    "Please confirm that **{$notifiable->email}** is your email address. You can browse auctions now; bidding opens once it is confirmed.",
                    'The link works for '.self::EXPIRE_HOURS.' hours.',
                ],
                'buttonLabel' => 'Confirm my email',
                'buttonUrl' => self::url($notifiable),
                'footnote' => 'If you did not create a LiveBid account, you can ignore this email.',
            ]);
    }
}

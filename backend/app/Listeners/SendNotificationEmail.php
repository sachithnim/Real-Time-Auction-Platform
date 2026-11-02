<?php

namespace App\Listeners;

use App\Events\User\AuctionWon;
use App\Events\User\AutoBidMaxReached;
use App\Events\User\Outbid;
use App\Events\User\PaymentReceived;
use App\Events\User\UserNotification;
use App\Events\User\WatchedAuctionReminder;
use App\Models\User;
use App\Notifications\AuctionEmail;
use App\Notifications\AuctionWonEmail;
use App\Notifications\MaxReachedEmail;
use App\Notifications\OutbidEmail;
use App\Notifications\PaymentReceiptEmail;
use App\Notifications\WatchReminderEmail;
use Illuminate\Support\Facades\Cache;

/**
 * Sends the email version of a user notification (the live toast is broadcast
 * separately). Runs after the change has committed; the email itself is queued,
 * so this only decides whether to send: the user's preference, and at most one
 * outbid email per auction every few minutes during a bidding war.
 */
class SendNotificationEmail
{
    /** Minutes between two outbid emails for the same bidder and auction. */
    public const OUTBID_EMAIL_INTERVAL_MINUTES = 10;

    /** @var array<class-string<UserNotification>, class-string<AuctionEmail>> */
    public const EMAILS = [
        Outbid::class => OutbidEmail::class,
        AutoBidMaxReached::class => MaxReachedEmail::class,
        AuctionWon::class => AuctionWonEmail::class,
        WatchedAuctionReminder::class => WatchReminderEmail::class,
        PaymentReceived::class => PaymentReceiptEmail::class,
    ];

    public function handle(UserNotification $event): void
    {
        $emailClass = self::EMAILS[$event::class] ?? null;
        $user = User::find($event->userId);

        if ($emailClass === null || $user === null) {
            return;
        }

        $email = new $emailClass($event->data);

        if (! $user->wantsEmail($email->type())) {
            return;
        }

        // Cache::add only succeeds for the first caller until the key expires.
        if ($event instanceof Outbid && ! Cache::add(
            "outbid-email:{$user->id}:{$event->data['auction_id']}",
            true,
            now()->addMinutes(self::OUTBID_EMAIL_INTERVAL_MINUTES),
        )) {
            return;
        }

        $user->notify($email);
    }
}

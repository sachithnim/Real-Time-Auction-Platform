<?php

namespace App\Listeners;

use App\Events\User\UserNotification;
use App\Models\Notice;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Keeps each private notification in the bidder's inbox, so one they missed
 * while away is still under the bell. Runs after the change has committed and
 * before the live event is broadcast (that goes through the queue), so the inbox
 * already has the entry when the browser hears about it.
 */
class StoreInboxNotice
{
    public function handle(UserNotification $event): void
    {
        $user = User::find($event->userId);

        if ($user === null || $user->isDisabled()) {
            return;
        }

        $type = class_basename($event);
        $auctionId = $event->data['auction_id'] ?? null;

        // A bidding war would otherwise fill the inbox with "Outbid" for one item:
        // an unread notice of the same kind for the same auction is brought up to date instead.
        $unread = $auctionId === null ? null : $user->unreadNotifications()
            ->where('type', $type)
            ->where('data->auction_id', $auctionId)
            ->first();

        if ($unread !== null) {
            $unread->forceFill(['data' => $event->data, 'updated_at' => now()])->save();

            return;
        }

        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => $event->data,
        ]);
    }
}

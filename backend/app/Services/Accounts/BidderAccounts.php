<?php

namespace App\Services\Accounts;

use App\Enums\AuctionEventType;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\User;
use App\Services\AuctionEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Admins blocking and unblocking bidders. A disabled bidder is signed out
 * everywhere, cannot log in, and their private maximums stop bidding for them.
 * Bids already placed stay: history is never rewritten, so a disabled bidder who
 * leads an auction keeps that bid until someone outbids it.
 */
class BidderAccounts
{
    public function __construct(private readonly AuctionEventRecorder $events) {}

    public function disable(User $bidder, User $admin): User
    {
        $this->ensureBidder($bidder);

        if ($bidder->isDisabled()) {
            return $bidder;
        }

        $bidder->forceFill(['disabled_at' => now()])->save();
        $bidder->signOutOtherSessions();
        // A reset link already in their inbox must not work either.
        Password::broker()->deleteToken($bidder);
        $this->stopAutoBids($bidder, $admin);

        return $bidder;
    }

    /** Lets them log in again. Their stopped maximums stay off: they can set new ones. */
    public function enable(User $bidder): User
    {
        $this->ensureBidder($bidder);
        $bidder->forceFill(['disabled_at' => null])->save();

        return $bidder;
    }

    private function ensureBidder(User $user): void
    {
        if (! $user->isBidder()) {
            throw new ApiException(ErrorCode::Forbidden, 'Only bidder accounts can be disabled or enabled.');
        }
    }

    /**
     * Each auction's auto-bids are read and changed by bids under that auction's row
     * lock, so switching one off takes the same lock: a bid in progress either sees it
     * still on (and finishes first) or already off, never half way.
     */
    private function stopAutoBids(User $bidder, User $admin): void
    {
        $auctionIds = AutoBid::where('user_id', $bidder->id)->where('is_active', true)->pluck('auction_id');

        foreach ($auctionIds as $auctionId) {
            DB::transaction(function () use ($auctionId, $bidder, $admin) {
                $auction = Auction::whereKey($auctionId)->lockForUpdate()->firstOrFail();
                $autoBid = AutoBid::where('auction_id', $auctionId)->where('user_id', $bidder->id)->where('is_active', true)->first();

                if ($autoBid === null) {
                    return;
                }

                $autoBid->forceFill(['is_active' => false])->save();
                $this->events->record($auction, AuctionEventType::AutoBidCancelled, $admin, [
                    'user_id' => $bidder->id,
                    'max_amount' => $autoBid->max_amount,
                    'reason' => 'ACCOUNT_DISABLED',
                ]);
            });
        }
    }
}

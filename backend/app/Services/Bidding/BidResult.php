<?php

namespace App\Services\Bidding;

use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\Bid;

/**
 * Outcome of a bid or an auto-bid change, as committed under the lock.
 */
final class BidResult
{
    /** The acting bidder's own visible bid, if one was placed. */
    public readonly ?Bid $bid;

    /**
     * @param  list<Bid>  $bids  visible bids inserted, in acceptance order
     * @param  list<int>  $outbidUserIds  bidders who lost the lead
     * @param  list<int>  $exhaustedUserIds  bidders whose auto-bid maximum was reached
     */
    public function __construct(
        public readonly Auction $auction,
        public readonly int $minNextBid,
        public readonly array $bids = [],
        public readonly ?AutoBid $autoBid = null,
        public readonly array $outbidUserIds = [],
        public readonly array $exhaustedUserIds = [],
        ?int $actingUserId = null,
        /** Whether this bid triggered an anti-sniping extension (new end is on $auction). */
        public readonly bool $extended = false,
    ) {
        $own = array_values(array_filter($bids, fn (Bid $bid) => $bid->user_id === $actingUserId));
        $this->bid = $own[0] ?? null;
    }
}

<?php

namespace App\Services\Realtime;

use App\Enums\AuctionEventType;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Services\Bidding\BidderPseudonyms;
use App\Services\Bidding\IncrementCalculator;
use App\Support\ServerTime;

/**
 * The admin live monitor (bonus challenge: admin live monitoring): every LIVE
 * auction with its price, leader and recent activity, plus the latest rejected
 * bids. Admin-only data: real identities and the reserve are included, so these
 * rows go only to admin endpoints and the private admin.monitor channel.
 */
class AdminMonitor
{
    /** Bids placed within this many minutes count as "recent" activity. */
    public const ACTIVITY_WINDOW_MINUTES = 5;

    /** How many rejected bids the monitor lists. */
    public const RECENT_REJECTIONS = 10;

    public function __construct(
        private readonly BidderPseudonyms $names,
        private readonly IncrementCalculator $increments,
    ) {}

    /**
     * @return array{auctions: list<array<string, mixed>>, recent_rejections: list<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $auctions = Auction::query()
            ->where('status', AuctionStatus::Live)
            ->with('leader')
            ->withCount('bids')
            ->orderBy('end_at')
            ->orderBy('id')
            ->get();

        return [
            'auctions' => $auctions->map(fn (Auction $auction) => $this->row($auction))->all(),
            'recent_rejections' => $this->recentRejections(),
        ];
    }

    /**
     * The current monitor row of one auction. A row whose status is no longer
     * LIVE tells the monitor to drop that auction.
     *
     * @return array<string, mixed>
     */
    public function rowFor(int $auctionId): array
    {
        $auction = Auction::query()->with('leader')->withCount('bids')->find($auctionId);

        return $auction === null ? ['id' => $auctionId, 'status' => null] : $this->row($auction);
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Auction $auction): array
    {
        $leader = $auction->leader;

        return [
            'id' => $auction->id,
            'title' => $auction->title,
            'status' => $auction->status->value,
            'starting_price' => $auction->starting_price,
            'current_price' => $auction->current_price,
            'reserve_price' => $auction->reserve_price,
            'reserve_met' => $auction->reserve_price === null
                || ($auction->current_price !== null && $auction->current_price >= $auction->reserve_price),
            'min_next_bid' => $auction->status->isFinal() ? null : $this->increments->minNextBid($auction),
            'leader' => $leader === null ? null : [
                'id' => $leader->id,
                'name' => $leader->name,
                'email' => $leader->email,
                'pseudonym' => $this->names->nameFor($auction, $leader->id, null),
            ],
            'bids_count' => $auction->bids_count ?? $auction->bids()->count(),
            'recent_bids' => $auction->bids()->reorder()
                ->where('created_at', '>=', now()->subMinutes(self::ACTIVITY_WINDOW_MINUTES))
                ->count(),
            'active_auto_bids' => $auction->autoBids()->where('is_active', true)->count(),
            'end_at' => ServerTime::format($auction->end_at),
            'original_end_at' => ServerTime::format($auction->original_end_at),
            'extensions_count' => $auction->extensions_count,
            'max_extensions' => $auction->max_extensions,
            'anti_snipe_seconds' => $auction->anti_snipe_seconds,
            'sequence' => $auction->event_sequence,
        ];
    }

    /**
     * Newest rejected bids first, across every auction.
     *
     * @return list<array<string, mixed>>
     */
    public function recentRejections(): array
    {
        return AuctionEvent::query()
            ->where('type', AuctionEventType::BidRejected)
            ->with(['actor', 'auction'])
            ->orderByDesc('id')
            ->limit(self::RECENT_REJECTIONS)
            ->get()
            ->map(fn (AuctionEvent $event) => $this->rejection($event))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function rejection(AuctionEvent $event): array
    {
        $payload = $event->payload;
        $bidder = $event->actor;

        return [
            'id' => $event->id,
            'auction_id' => $event->auction_id,
            'auction_title' => $event->auction?->title,
            'bidder' => $bidder === null ? null : ['id' => $bidder->id, 'name' => $bidder->name, 'email' => $bidder->email],
            'action' => $payload['action'] ?? 'BID',
            'amount' => $payload['amount'] ?? null,
            'reason' => $payload['reason'] ?? null,
            'message' => $payload['message'] ?? null,
            'created_at' => $event->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}

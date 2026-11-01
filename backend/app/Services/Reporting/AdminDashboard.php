<?php

namespace App\Services\Reporting;

use App\Enums\AuctionEventType;
use App\Enums\AuctionResult;
use App\Enums\AuctionStatus;
use App\Enums\FlagStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\Bid;
use App\Models\BidFlag;
use App\Models\Payment;
use App\Models\User;
use App\Support\ServerTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin home page: what is happening now, what happened in the last 24 hours,
 * and what needs the admin's attention. Admin-only data (real names), so it is
 * returned only from an admin endpoint.
 */
class AdminDashboard
{
    /** Rows in each short list on the dashboard. */
    public const LIST_SIZE = 5;

    /** Entries in the recent activity feed. */
    public const ACTIVITY_SIZE = 8;

    /** Events worth showing in the feed: the milestones, not every edit. */
    private const ACTIVITY_TYPES = [
        AuctionEventType::AuctionCreated, AuctionEventType::AuctionScheduled, AuctionEventType::AuctionStarted,
        AuctionEventType::BidPlaced, AuctionEventType::AuctionExtended, AuctionEventType::WinnerSelected,
        AuctionEventType::ReserveNotMet, AuctionEventType::AuctionCancelled, AuctionEventType::PaymentSucceeded,
    ];

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        // A rolling 24 hours: the same for every admin, whatever their time zone.
        $since = now()->subDay();
        $counts = Auction::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return [
            'auctions' => [
                'draft' => (int) ($counts[AuctionStatus::Draft->value] ?? 0),
                'scheduled' => (int) ($counts[AuctionStatus::Scheduled->value] ?? 0),
                'live' => (int) ($counts[AuctionStatus::Live->value] ?? 0),
            ],
            'last_24_hours' => [
                'bids' => Bid::query()->where('created_at', '>=', $since)->count(),
                'active_bidders' => Bid::query()->where('created_at', '>=', $since)->distinct()->count('user_id'),
                'new_bidders' => User::query()->where('role', UserRole::Bidder)->where('created_at', '>=', $since)->count(),
                'auctions_ended' => Auction::query()->where('status', AuctionStatus::Completed)->where('ended_at', '>=', $since)->count(),
                'sold_value' => (int) Auction::query()
                    ->where('result', AuctionResult::Sold)
                    ->where('ended_at', '>=', $since)
                    ->sum('final_price'),
            ],
            'attention' => [
                'open_flags' => BidFlag::query()->where('status', FlagStatus::Open)->count(),
                'unpaid_wins' => Payment::query()->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Failed])->count(),
                // Sold auctions whose winner has not started paying yet have no payment row.
                'unpaid_sold' => Auction::query()->where('result', AuctionResult::Sold)->whereDoesntHave('payment')->count(),
            ],
            'ending_soon' => $this->auctionRows(
                Auction::query()->where('status', AuctionStatus::Live)->orderBy('end_at'),
                fn (Auction $a) => ['current_price' => $a->current_price, 'end_at' => ServerTime::format($a->end_at), 'leader' => $a->leader?->name],
            ),
            'starting_soon' => $this->auctionRows(
                Auction::query()->where('status', AuctionStatus::Scheduled)->orderBy('start_at'),
                fn (Auction $a) => ['starting_price' => $a->starting_price, 'start_at' => ServerTime::format($a->start_at)],
            ),
            'drafts' => $this->auctionRows(
                Auction::query()->where('status', AuctionStatus::Draft)->orderByDesc('updated_at'),
                fn (Auction $a) => ['updated_at' => ServerTime::format($a->updated_at), 'start_at' => ServerTime::format($a->start_at)],
            ),
            'recent_activity' => AuctionEvent::query()
                ->whereIn('type', self::ACTIVITY_TYPES)
                ->with(['auction:id,title', 'actor:id,name'])
                ->orderByDesc('id')
                ->limit(self::ACTIVITY_SIZE)
                ->get()
                ->map(fn (AuctionEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->type->value,
                    'auction' => ['id' => $event->auction_id, 'title' => $event->auction?->title],
                    'actor' => $event->actor?->name,
                    'amount' => $event->payload['amount'] ?? $event->payload['final_price'] ?? null,
                    'created_at' => ServerTime::format($event->created_at),
                ])
                ->all(),
        ];
    }

    /**
     * The first few auctions of a query, each with the fields every list shares
     * (id, title, photo count, bids) plus its own.
     *
     * @param  Builder<Auction>  $query
     * @param  callable(Auction): array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function auctionRows(Builder $query, callable $extra): array
    {
        return $query->with('leader:id,name')
            ->withCount(['bids', 'images'])
            ->orderBy('id')
            ->limit(self::LIST_SIZE)
            ->get()
            ->map(fn (Auction $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'bids_count' => $a->bids_count,
                'images_count' => $a->images_count,
                ...$extra($a),
            ])
            ->all();
    }
}

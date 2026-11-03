<?php

namespace App\Http\Controllers;

use App\Enums\AuctionStatus;
use App\Enums\PaymentStatus;
use App\Http\Requests\ListMyBidsRequest;
use App\Http\Resources\MyBidResource;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Support\ServerTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The signed-in bidder's own auctions: every auction they have bid on, with
 * how they stand in each. Only their own bids and maximums are read.
 */
class MyBidsController extends Controller
{
    public function index(ListMyBidsRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $auctions = $this->participated($user)
            ->with(['category', 'images', 'payment', 'autoBids' => fn ($autoBids) => $autoBids->where('user_id', $user->id)])
            ->withCount(['bids', 'bids as my_bids_count' => fn ($bids) => $bids->where('user_id', $user->id)])
            ->withMax(['bids as my_highest_bid' => fn ($bids) => $bids->where('user_id', $user->id)], 'amount')
            ->withExists(['watchers as is_watching' => fn ($watchers) => $watchers->whereKey($user->id)])
            ->tap(fn ($query) => $this->applyFilter($query, $request->validated('filter'), $user))
            // Live first (ending soonest), then finished ones, most recent first.
            ->orderByRaw("status = 'LIVE' DESC")
            ->orderByRaw("CASE WHEN status = 'LIVE' THEN end_at END ASC")
            ->orderByRaw('COALESCE(ended_at, cancelled_at, end_at) DESC')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return MyBidResource::collection($auctions)->additional([
            'counts' => $this->counts($user),
            ...ServerTime::meta(),
        ]);
    }

    /**
     * Auctions with at least one of the user's bids. Setting a maximum always
     * places a bid, so this also covers every auction with their auto-bid.
     *
     * @return Builder<Auction>
     */
    private function participated(User $user): Builder
    {
        return Auction::query()->whereIn('id', Bid::query()->select('auction_id')->where('user_id', $user->id));
    }

    /**
     * @param  Builder<Auction>  $query
     */
    private function applyFilter(Builder $query, ?string $filter, User $user): void
    {
        match ($filter) {
            'active' => $query->where('status', AuctionStatus::Live),
            'won' => $query->where('status', AuctionStatus::Completed)->where('winner_id', $user->id),
            'lost' => $query->where(fn ($lost) => $lost
                ->where('status', AuctionStatus::Cancelled)
                ->orWhere(fn ($completed) => $completed
                    ->where('status', AuctionStatus::Completed)
                    ->where(fn ($notMine) => $notMine->whereNull('winner_id')->orWhere('winner_id', '!=', $user->id)))),
            default => null,
        };
    }

    /**
     * The number behind each tab, and how many wins still wait for payment, in one query.
     *
     * @return array<string, int>
     */
    private function counts(User $user): array
    {
        $row = $this->participated($user)
            ->selectRaw('COUNT(*) AS `all`')
            ->selectRaw("COALESCE(SUM(status = 'LIVE'), 0) AS active")
            ->selectRaw("COALESCE(SUM(status = 'COMPLETED' AND winner_id = ?), 0) AS won", [$user->id])
            ->selectRaw("COALESCE(SUM(status = 'CANCELLED' OR (status = 'COMPLETED' AND (winner_id IS NULL OR winner_id <> ?))), 0) AS lost", [$user->id])
            ->selectRaw(
                "COALESCE(SUM(status = 'COMPLETED' AND winner_id = ? AND NOT EXISTS (
                    SELECT 1 FROM payments WHERE payments.auction_id = auctions.id AND payments.status = ?
                )), 0) AS to_pay",
                [$user->id, PaymentStatus::Paid->value],
            )
            ->toBase()
            ->first();

        return collect((array) $row)->map(fn ($count) => (int) $count)->all();
    }
}

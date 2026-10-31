<?php

namespace App\Http\Controllers;

use App\Enums\AuctionStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Requests\ListPublicAuctionsRequest;
use App\Http\Resources\AuctionResource;
use App\Models\Auction;
use App\Support\ServerTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Public auction listing and details (guests and bidders).
 */
class AuctionController extends Controller
{
    public function index(ListPublicAuctionsRequest $request): AnonymousResourceCollection
    {
        $viewer = $request->user();

        if ($request->boolean('watching') && $viewer === null) {
            throw new ApiException(ErrorCode::Unauthenticated, 'Log in to see the auctions you are watching.');
        }

        $auctions = Auction::query()
            ->with('category')
            ->withCount('bids')
            // The viewer's own watch status, in the same query (no per-row lookups).
            ->when($viewer, fn ($query) => $query->withExists([
                'watchers as is_watching' => fn ($watchers) => $watchers->whereKey($viewer->id),
            ]))
            ->where('status', '!=', AuctionStatus::Draft)
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->validated('category'), fn ($query, $slug) => $query->whereRelation('category', 'slug', $slug))
            ->when($request->boolean('watching'), fn ($query) => $query->whereHas(
                'watchers', fn ($watchers) => $watchers->whereKey($viewer->id),
            ))
            ->when($request->validated('search'), fn ($query, $search) => $this->searchTitles($query, $search))
            ->tap(fn ($query) => $this->applySort($query, $request->validated('sort')))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return AuctionResource::collection($auctions)->additional(ServerTime::meta());
    }

    /**
     * Every word must appear in the title (in any order). LIKE wildcards typed by the
     * user are escaped, so "100%" matches a literal percent sign.
     *
     * @param  Builder<Auction>  $query
     */
    private function searchTitles(Builder $query, string $search): void
    {
        foreach (preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where('title', 'like', '%'.addcslashes($word, '\\%_').'%');
        }
    }

    /**
     * @param  Builder<Auction>  $query
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        match ($sort) {
            // Auctions still open (live or upcoming) first, by the soonest end; finished ones after.
            'ending_soon' => $query
                ->orderByRaw("status IN ('LIVE', 'SCHEDULED') DESC")
                ->orderBy('end_at'),
            'newest' => $query->orderByDesc('created_at'),
            // The price a bidder sees: the current price, or the starting price before any bid.
            'price_high' => $query->orderByRaw('COALESCE(current_price, starting_price) DESC'),
            'price_low' => $query->orderByRaw('COALESCE(current_price, starting_price) ASC'),
            // Live first (ending soonest), then upcoming (starting soonest), then finished (most recent first).
            default => $query
                ->orderByRaw("FIELD(status, 'LIVE', 'SCHEDULED', 'COMPLETED', 'CANCELLED')")
                ->orderByRaw("CASE WHEN status = 'LIVE' THEN end_at END ASC")
                ->orderByRaw("CASE WHEN status = 'SCHEDULED' THEN start_at END ASC")
                ->orderByDesc('end_at'),
        };
    }

    public function show(Auction $auction): AuctionResource
    {
        // A draft is reported as missing rather than forbidden, so its existence is not revealed.
        if (Gate::denies('view', $auction)) {
            throw new ApiException(ErrorCode::AuctionNotFound, 'Auction not found.');
        }

        $auction->load('category')->loadCount('bids');

        return (new AuctionResource($auction))->additional(ServerTime::meta());
    }
}

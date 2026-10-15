<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAuctionEventsRequest;
use App\Http\Requests\ListBidsRequest;
use App\Http\Resources\Admin\AdminBidResource;
use App\Http\Resources\Admin\AuctionEventResource;
use App\Models\Auction;
use App\Models\Bid;
use App\Support\ServerTime;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Full-detail history for admins: bids with real identities and the audit trail.
 */
class AuctionHistoryController extends Controller
{
    public function bids(ListBidsRequest $request, Auction $auction): AnonymousResourceCollection
    {
        Gate::authorize('manage', Auction::class);

        $bids = $auction->bids()->reorder()
            ->with('user')
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('per_page', 50))
            ->through(fn (Bid $bid) => $bid->setRelation('auction', $auction));

        return AdminBidResource::collection($bids)->additional(ServerTime::meta());
    }

    /** The audit trail in the order it was written. */
    public function events(ListAuctionEventsRequest $request, Auction $auction): AnonymousResourceCollection
    {
        Gate::authorize('manage', Auction::class);

        $events = $auction->events()
            ->with('actor')
            ->when($request->validated('type'), fn ($query, $type) => $query->where('type', $type))
            ->paginate($request->integer('per_page', 100));

        return AuctionEventResource::collection($events)->additional(ServerTime::meta());
    }
}

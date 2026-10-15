<?php

namespace App\Http\Controllers;

use App\Enums\AuctionStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Requests\ListPublicAuctionsRequest;
use App\Http\Resources\AuctionResource;
use App\Models\Auction;
use App\Support\ServerTime;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Public auction listing and details (guests and bidders).
 */
class AuctionController extends Controller
{
    public function index(ListPublicAuctionsRequest $request): AnonymousResourceCollection
    {
        $auctions = Auction::query()
            ->withCount('bids')
            ->where('status', '!=', AuctionStatus::Draft)
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            // Live first (ending soonest), then upcoming (starting soonest), then finished (most recent first).
            ->orderByRaw("FIELD(status, 'LIVE', 'SCHEDULED', 'COMPLETED', 'CANCELLED')")
            ->orderByRaw("CASE WHEN status = 'LIVE' THEN end_at END ASC")
            ->orderByRaw("CASE WHEN status = 'SCHEDULED' THEN start_at END ASC")
            ->orderByDesc('end_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return AuctionResource::collection($auctions)->additional(ServerTime::meta());
    }

    public function show(Auction $auction): AuctionResource
    {
        // A draft is reported as missing rather than forbidden, so its existence is not revealed.
        if (Gate::denies('view', $auction)) {
            throw new ApiException(ErrorCode::AuctionNotFound, 'Auction not found.');
        }

        $auction->loadCount('bids');

        return (new AuctionResource($auction))->additional(ServerTime::meta());
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Requests\SetAutoBidRequest;
use App\Http\Resources\AuctionResource;
use App\Http\Resources\AutoBidResource;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Services\Bidding\BidService;
use App\Support\ServerTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The signed-in bidder's own auto-bid (private maximum) for an auction.
 */
class AutoBidController extends Controller
{
    public function __construct(private readonly BidService $bids) {}

    public function show(Request $request, Auction $auction): JsonResponse
    {
        $this->ensureVisible($auction);

        $autoBid = AutoBid::where('auction_id', $auction->id)->where('user_id', $request->user()->id)->first();

        return response()->json([
            'data' => $autoBid ? new AutoBidResource($autoBid) : null,
            ...ServerTime::meta(),
        ]);
    }

    public function update(SetAutoBidRequest $request, Auction $auction): JsonResponse
    {
        $this->ensureVisible($auction);

        $result = $this->bids->configureAutoBid($auction, $request->user(), $request->integer('max_amount'));

        return response()->json([
            'data' => [
                'auto_bid' => new AutoBidResource($result->autoBid),
                'auction' => new AuctionResource($result->auction->loadCount('bids')),
            ],
            ...ServerTime::meta(),
        ]);
    }

    private function ensureVisible(Auction $auction): void
    {
        if (Gate::denies('view', $auction)) {
            throw new ApiException(ErrorCode::AuctionNotFound, 'Auction not found.');
        }
    }
}

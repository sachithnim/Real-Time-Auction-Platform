<?php

namespace App\Http\Controllers;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Requests\ListBidsRequest;
use App\Http\Requests\PlaceBidRequest;
use App\Http\Resources\AuctionResource;
use App\Http\Resources\BidHistoryResource;
use App\Http\Resources\BidResource;
use App\Models\Auction;
use App\Models\Bid;
use App\Services\Bidding\BidService;
use App\Support\ServerTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class BidController extends Controller
{
    public function __construct(private readonly BidService $bids) {}

    /**
     * Live bid history, newest first. Cursor pagination keeps pages stable
     * while new bids keep arriving at the top.
     */
    public function index(ListBidsRequest $request, Auction $auction): AnonymousResourceCollection
    {
        if (Gate::denies('view', $auction)) {
            throw new ApiException(ErrorCode::AuctionNotFound, 'Auction not found.');
        }

        $bids = $auction->bids()->reorder()
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('per_page', 50))
            ->through(fn (Bid $bid) => $bid->setRelation('auction', $auction));

        return BidHistoryResource::collection($bids)->additional(ServerTime::meta());
    }

    public function store(PlaceBidRequest $request, Auction $auction): JsonResponse
    {
        // Drafts stay invisible to bidders, here as everywhere else.
        if (Gate::denies('view', $auction)) {
            throw new ApiException(ErrorCode::AuctionNotFound, 'Auction not found.');
        }

        $result = $this->bids->placeBid($auction, $request->user(), $request->integer('amount'));

        return response()->json([
            'data' => [
                'bid' => new BidResource($result->bid),
                'auction' => new AuctionResource($result->auction->loadCount('bids')),
            ],
            ...ServerTime::meta(),
        ], 201);
    }
}

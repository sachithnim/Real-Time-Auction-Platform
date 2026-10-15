<?php

namespace App\Http\Controllers;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Requests\PlaceBidRequest;
use App\Http\Resources\AuctionResource;
use App\Http\Resources\BidResource;
use App\Models\Auction;
use App\Services\Bidding\BidService;
use App\Support\ServerTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BidController extends Controller
{
    public function __construct(private readonly BidService $bids) {}

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

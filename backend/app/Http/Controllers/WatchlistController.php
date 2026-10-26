<?php

namespace App\Http\Controllers;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Support\ServerTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The signed-in bidder's watchlist (bonus challenge: watchlists). Both actions
 * are idempotent: watching twice or unwatching something not watched is fine.
 */
class WatchlistController extends Controller
{
    public function store(Request $request, Auction $auction): JsonResponse
    {
        $this->ensureVisible($auction);
        $request->user()->watch($auction);

        return $this->respond($auction, true);
    }

    public function destroy(Request $request, Auction $auction): JsonResponse
    {
        $this->ensureVisible($auction);
        $request->user()->unwatch($auction);

        return $this->respond($auction, false);
    }

    private function ensureVisible(Auction $auction): void
    {
        // Drafts stay invisible to bidders, here as everywhere else.
        if (Gate::denies('view', $auction)) {
            throw new ApiException(ErrorCode::AuctionNotFound, 'Auction not found.');
        }
    }

    private function respond(Auction $auction, bool $watching): JsonResponse
    {
        return response()->json([
            'data' => ['auction_id' => $auction->id, 'is_watching' => $watching],
            ...ServerTime::meta(),
        ]);
    }
}

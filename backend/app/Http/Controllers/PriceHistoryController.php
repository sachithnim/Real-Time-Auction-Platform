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
 * The price over time, for the chart on the auction page: each accepted bid's
 * amount, type and time, oldest first. Like the public bid history it names no
 * one; it only marks which points are the viewer's own bids.
 */
class PriceHistoryController extends Controller
{
    /** Points sent at most: the most recent ones. A chart has no use for more. */
    public const MAX_POINTS = 500;

    public function show(Request $request, Auction $auction): JsonResponse
    {
        if (Gate::denies('view', $auction)) {
            throw new ApiException(ErrorCode::AuctionNotFound, 'Auction not found.');
        }

        $viewerId = $request->user()?->id;
        $points = $auction->bids()->reorder()
            ->orderByDesc('id')
            ->limit(self::MAX_POINTS)
            ->get(['id', 'user_id', 'amount', 'type', 'created_at'])
            ->reverse()
            ->map(fn ($bid) => [
                'amount' => $bid->amount,
                'type' => $bid->type->value,
                'at' => ServerTime::format($bid->created_at),
                'mine' => $viewerId !== null && $bid->user_id === $viewerId,
            ])
            ->values();

        return response()->json([
            'data' => [
                'starting_price' => $auction->starting_price,
                'start_at' => ServerTime::format($auction->started_at ?? $auction->start_at),
                'end_at' => ServerTime::format($auction->ended_at ?? $auction->end_at),
                'points' => $points,
            ],
            ...ServerTime::meta(),
        ]);
    }
}

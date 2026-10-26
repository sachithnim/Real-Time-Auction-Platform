<?php

namespace App\Http\Middleware;

use App\Models\Auction;
use App\Services\Monitoring\SuspiciousBidDetector;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function Illuminate\Support\defer;

/**
 * After a bid or auto-bid request has been answered, checks the bidder's
 * activity for suspicious patterns. Deferred until the response is sent, so
 * the bidder never waits for it; runs for rejected bids too ("always"), since
 * repeated rejections are one of the patterns. A failure is only logged.
 */
class ScanBiddingActivity
{
    public function __construct(private readonly SuspiciousBidDetector $detector) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $auction = $request->route('auction');
        $user = $request->user();

        if ($auction instanceof Auction && $user !== null) {
            $auctionId = $auction->id;
            $userId = $user->id;
            $ip = $request->ip();

            defer(function () use ($auctionId, $userId, $ip) {
                try {
                    $this->detector->scan($auctionId, $userId, $ip);
                } catch (Throwable $e) {
                    Log::warning('Suspicious bidding scan failed.', ['auction_id' => $auctionId, 'error' => $e->getMessage()]);
                }
            }, always: true);
        }

        return $response;
    }
}

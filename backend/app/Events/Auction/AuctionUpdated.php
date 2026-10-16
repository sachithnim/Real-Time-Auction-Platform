<?php

namespace App\Events\Auction;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Listing-level summary on the public "auctions" channel, so the auction list
 * updates without a refresh.
 */
final class AuctionUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(public readonly array $summary) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('auctions')];
    }

    public function broadcastAs(): string
    {
        return 'AuctionUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->summary;
    }
}

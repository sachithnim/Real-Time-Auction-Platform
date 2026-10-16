<?php

namespace App\Events\Auction;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An event on the public auction.{id} channel. Payloads are built by
 * AuctionBroadcaster (where the privacy rules live) and sent only after the
 * database transaction commits, so a rolled-back change is never announced.
 * Every event carries the auction's next sequence number and server_time.
 */
abstract class AuctionChannelEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $auctionId,
        public readonly int $sequence,
        public readonly string $serverTime,
        public readonly array $data,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel("auction.{$this->auctionId}")];
    }

    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'auction_id' => $this->auctionId,
            'sequence' => $this->sequence,
            'server_time' => $this->serverTime,
            ...$this->data,
        ];
    }
}

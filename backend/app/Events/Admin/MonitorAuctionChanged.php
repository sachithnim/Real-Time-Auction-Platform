<?php

namespace App\Events\Admin;

use App\Services\Realtime\AdminMonitor;
use App\Support\ServerTime;

/**
 * Something changed on an auction (bid, start, end, extension, cancellation).
 * The monitor row is built when the queue worker sends the event, so it shows
 * the committed state at that moment and never adds work under the bid lock.
 */
final class MonitorAuctionChanged extends AdminMonitorEvent
{
    public function __construct(public readonly int $auctionId) {}

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'auction' => app(AdminMonitor::class)->rowFor($this->auctionId),
            'server_time' => ServerTime::now(),
        ];
    }
}

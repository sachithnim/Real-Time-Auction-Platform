<?php

namespace App\Events\Admin;

use App\Support\ServerTime;

/** A bid or auto-bid was rejected; shown in the monitor's recent rejections. */
final class MonitorBidRejected extends AdminMonitorEvent
{
    /**
     * @param  array<string, mixed>  $rejection  built by AdminMonitor::rejection()
     */
    public function __construct(public readonly array $rejection) {}

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['rejection' => $this->rejection, 'server_time' => ServerTime::now()];
    }
}

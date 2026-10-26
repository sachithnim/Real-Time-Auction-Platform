<?php

namespace App\Events\Admin;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An event on the private admin.monitor channel, which only admins can join.
 * Carries admin-only data (identities, reserve) and is sent only after commit.
 */
abstract class AdminMonitorEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const CHANNEL = 'admin.monitor';

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerTest extends TestCase
{
    /**
     * @return array<string, Event>
     */
    private function sweeps(): array
    {
        $sweeps = [];
        foreach (app(Schedule::class)->events() as $event) {
            foreach (['auctions:start-due', 'auctions:close-due', 'auctions:remind-watchers'] as $command) {
                if (str_contains((string) $event->command, $command)) {
                    $sweeps[$command] = $event;
                }
            }
        }

        return $sweeps;
    }

    public function test_every_auction_sweep_is_scheduled_without_overlapping(): void
    {
        $sweeps = $this->sweeps();

        $this->assertSame(['auctions:start-due', 'auctions:close-due', 'auctions:remind-watchers'], array_keys($sweeps));
        foreach ($sweeps as $command => $event) {
            $this->assertTrue($event->withoutOverlapping, "{$command} must not overlap itself.");
        }
    }

    public function test_a_stale_sweep_lock_expires_within_minutes_not_a_day(): void
    {
        // A scheduler stopped mid-run leaves its lock behind; it must not block the
        // closing and starting safety nets for Laravel's default of 24 hours.
        foreach ($this->sweeps() as $command => $event) {
            $this->assertLessThanOrEqual(10, $event->expiresAt, "{$command} lock lives {$event->expiresAt} minutes.");
        }
    }
}

<?php

namespace Tests\Feature\Concurrency;

use App\Models\Auction;
use PHPUnit\Framework\Attributes\Group;

#[Group('concurrency')]
class HarnessTest extends ConcurrencyTestCase
{
    public function test_workers_are_separate_processes_with_their_own_connections_running_in_parallel(): void
    {
        $auction = Auction::factory()->live()->create();

        $results = $this->runConcurrently(array_fill(0, 4, ['action' => 'probe', 'auction' => $auction->id]));

        $this->assertCount(4, array_unique(array_column($results, 'pid')), 'Each worker is its own process.');
        $this->assertCount(4, array_unique(array_column($results, 'connection_id')), 'Each worker has its own MySQL connection.');

        // All four held for 0.5 s; run one after another that would take 2 s.
        $span = max(array_column($results, 'finished')) - min(array_column($results, 'started'));
        $this->assertLessThan(1.5, $span, 'Workers overlapped in time.');
    }
}

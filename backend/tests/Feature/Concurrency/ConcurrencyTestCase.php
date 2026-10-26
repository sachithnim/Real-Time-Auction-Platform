<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

/**
 * Base for tests that run real parallel processes against MySQL.
 *
 * Data is committed (no wrapping test transaction) so other processes can see
 * it, and truncated after each test. Each worker is a separate PHP process with
 * its own database connection; all workers wait for a shared start time and
 * then act at the same instant.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected $exceptTables = ['migrations', 'bid_increment_rules'];

    /** Measured once per test run: how long one worker takes to start on this machine. */
    private static ?float $measuredBootSeconds = null;

    /**
     * Workers need time to boot Laravel before the shared start. The wait is
     * calibrated from a real worker start, because it ranges from about a second
     * on a Linux disk to many seconds on a slow mount (e.g. Docker on a Windows
     * drive). CONCURRENCY_BOOT_SECONDS overrides the measurement.
     */
    private function bootSeconds(): float
    {
        $configured = env('CONCURRENCY_BOOT_SECONDS');
        if ($configured !== null && $configured !== '') {
            return max(2.0, (float) $configured);
        }

        // Workers boot in parallel and then warm up, so allow a generous multiple.
        return self::$measuredBootSeconds ??= max(2.0, $this->timeOneWorkerBoot() * 3 + 1);
    }

    private function timeOneWorkerBoot(): float
    {
        $started = microtime(true);

        Process::path(base_path())
            ->env($this->workerEnvironment())
            ->timeout(300)
            ->run([PHP_BINARY, 'tests/Concurrency/worker.php', json_encode(['action' => 'boot'])])
            ->throw();

        return microtime(true) - $started;
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    /**
     * Runs every job in its own process, released at the same instant.
     *
     * @param  list<array<string, mixed>>  $jobs  a job may add 'delay' seconds to the shared start
     * @param  float|null  $startAt  shared release time (unix seconds); default: once workers have booted
     * @return list<array<string, mixed>> one decoded result per job, in job order
     */
    protected function runConcurrently(array $jobs, ?float $startAt = null): array
    {
        $startAt ??= microtime(true) + $this->bootSeconds();
        $env = $this->workerEnvironment();

        $results = Process::pool(function (Pool $pool) use ($jobs, $startAt, $env) {
            foreach ($jobs as $index => $job) {
                $pool->as((string) $index)
                    ->path(base_path())
                    ->env($env)
                    ->timeout((int) ceil($this->bootSeconds()) + 60)
                    ->command([PHP_BINARY, 'tests/Concurrency/worker.php', json_encode($job + ['start_at' => $startAt])]);
            }
        })->start()->wait();

        $decoded = [];
        foreach (array_keys($jobs) as $index) {
            $result = $results[(string) $index];
            $line = trim((string) last(explode("\n", trim($result->output()))));
            $data = json_decode($line, true);

            if (! $result->successful() || ! is_array($data)) {
                throw new RuntimeException("Worker {$index} failed: ".$result->output().$result->errorOutput());
            }

            $decoded[] = $data;
        }

        return $decoded;
    }

    /** A shared release time far enough ahead for every worker to boot. */
    protected function releaseTimeAfterBoot(): float
    {
        return microtime(true) + $this->bootSeconds();
    }

    /**
     * Same database and settings as this test process.
     *
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $db = config('database.connections.mysql');

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'database',
            'BROADCAST_CONNECTION' => 'null',
            // Allow for many workers queueing on the same row lock.
            'AUCTION_BID_LOCK_WAIT_SECONDS' => '10',
        ];
    }
}

<?php

/**
 * One concurrent actor for the concurrency tests. Runs as its own PHP process
 * with its own MySQL connection, waits until a shared start time so all
 * actors hit the database at the same instant, performs one action and
 * prints the outcome as a single JSON line.
 *
 * Usage: php tests/Concurrency/worker.php '<json job>'
 */

use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\User;
use App\Services\Bidding\BidService;
use App\Services\Bidding\IncrementCalculator;
use App\Services\Lifecycle\AuctionCloser;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$job = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

// Open the connection and load models before the barrier, so the release is instant.
$auction = Auction::findOrFail($job['auction']);
$user = isset($job['user']) ? User::findOrFail($job['user']) : null;

// Optional per-job offset, e.g. to land just before or after an auction's end.
while (microtime(true) < $job['start_at'] + ($job['delay'] ?? 0)) {
    usleep(100);
}

$attempt = function (callable $action): array {
    try {
        return ['ok' => true] + $action();
    } catch (ApiException $e) {
        return ['ok' => false, 'code' => $e->errorCode->value];
    }
};

$output = match ($job['action']) {
    // Harness self-check: report this process's own MySQL connection and timing.
    'probe' => (function () {
        $started = microtime(true);
        $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        usleep(500_000);

        return ['ok' => true, 'connection_id' => $connectionId, 'pid' => getmypid(), 'started' => $started, 'finished' => microtime(true)];
    })(),

    // A manual bid of a fixed amount.
    'bid' => $attempt(fn () => [
        'bid_id' => app(BidService::class)->placeBid($auction, $user, $job['amount'])->bid?->id,
    ]),

    // Set an auto-bid maximum.
    'auto' => $attempt(fn () => [
        'leader' => app(BidService::class)->configureAutoBid($auction, $user, $job['amount'])->auction->leader_id,
    ]),

    // Keep bidding the minimum next bid as read (without a lock) from the database.
    'race' => (function () use ($auction, $user, $job, $attempt) {
        $accepted = 0;
        $rejected = 0;
        for ($i = 0; $i < $job['rounds']; $i++) {
            $fresh = $auction->fresh();
            $amount = app(IncrementCalculator::class)->minNextBid($fresh);
            $result = $attempt(fn () => ['id' => app(BidService::class)->placeBid($fresh, $user, $amount)->bid->id]);
            $result['ok'] ? $accepted++ : $rejected++;
        }

        return ['ok' => true, 'accepted' => $accepted, 'rejected' => $rejected];
    })(),

    // Try to close until the auction is due, then report what happened.
    'close' => (function () use ($auction, $job) {
        $deadline = microtime(true) + ($job['timeout'] ?? 3);
        do {
            $outcome = app(AuctionCloser::class)->close($auction->id);
            if (! $outcome->isNotDue()) {
                return ['ok' => true, 'status' => $outcome->status];
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return ['ok' => false, 'status' => 'TIMEOUT'];
    })(),

    // A full HTTP request through middleware (auth, role, idempotency, validation).
    'http_bid' => (function () use ($app, $auction, $user, $job) {
        Auth::guard('web')->setUser($user);
        $request = Request::create("/api/auctions/{$auction->id}/bids", 'POST', server: [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => $job['key'],
        ], content: json_encode(['amount' => $job['amount']]));

        $response = $app->make(HttpKernel::class)->handle($request);

        return [
            'ok' => $response->getStatusCode() < 400,
            'status' => $response->getStatusCode(),
            'replayed' => $response->headers->get('Idempotent-Replayed') === 'true',
            'body' => json_decode($response->getContent(), true),
        ];
    })(),
};

fwrite(STDOUT, json_encode($output).PHP_EOL);

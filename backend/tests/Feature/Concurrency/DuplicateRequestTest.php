<?php

namespace Tests\Feature\Concurrency;

use App\Enums\IdempotencyStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

#[Group('concurrency')]
class DuplicateRequestTest extends ConcurrencyTestCase
{
    public function test_the_same_request_sent_five_times_at_once_places_one_bid(): void
    {
        $auction = Auction::factory()->live()->create(['starting_price' => 20_000, 'reserve_price' => null]);
        $bidder = User::factory()->create();
        $key = (string) Str::uuid();

        $results = $this->runConcurrently(array_fill(0, 5, [
            'action' => 'http_bid',
            'auction' => $auction->id,
            'user' => $bidder->id,
            'amount' => 20_000,
            'key' => $key,
        ]));

        $this->assertSame(1, Bid::where('auction_id', $auction->id)->count(), 'Duplicates must not place extra bids.');

        $created = array_values(array_filter($results, fn ($r) => $r['status'] === 201));
        $duplicates = array_values(array_filter($results, fn ($r) => $r['status'] === 409));
        $this->assertSame(5, count($created) + count($duplicates), 'Every response is either the bid or a duplicate notice.');
        $this->assertNotEmpty($created);

        // Late arrivals get the stored response replayed: always the same bid.
        $this->assertCount(1, array_unique(array_map(fn ($r) => $r['body']['data']['bid']['id'], $created)));
        $this->assertSame(1, count(array_filter($created, fn ($r) => ! $r['replayed'])), 'Only one request actually ran.');
        foreach ($duplicates as $duplicate) {
            $this->assertSame('DUPLICATE_REQUEST', $duplicate['body']['error']['code']);
        }

        $this->assertSame(IdempotencyStatus::Completed, IdempotencyKey::sole()->status);
        $this->assertSame(20_000, $auction->fresh()->current_price);
    }
}

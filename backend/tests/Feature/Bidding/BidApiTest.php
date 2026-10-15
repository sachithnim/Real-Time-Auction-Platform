<?php

namespace Tests\Feature\Bidding;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BidApiTest extends TestCase
{
    use RefreshDatabase;

    private User $bidder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bidder = User::factory()->create();
    }

    private function liveAuction(array $attributes = []): Auction
    {
        return Auction::factory()->live()->create(array_merge([
            'starting_price' => 20_000,
            'reserve_price' => null,
            'min_increment' => 500,
        ], $attributes));
    }

    private function bid(Auction|int $auction, mixed $amount, ?User $user = null, ?string $key = null): TestResponse
    {
        $id = $auction instanceof Auction ? $auction->id : $auction;

        return $this->actingAs($user ?? $this->bidder)
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson("/api/auctions/{$id}/bids", ['amount' => $amount]);
    }

    public function test_bidder_places_a_bid(): void
    {
        $auction = $this->liveAuction();

        $this->bid($auction, 20_000)
            ->assertCreated()
            ->assertJsonPath('data.bid.amount', 20_000)
            ->assertJsonPath('data.bid.type', 'MANUAL')
            ->assertJsonPath('data.auction.current_price', 20_000)
            ->assertJsonPath('data.auction.min_next_bid', 20_500)
            ->assertJsonPath('data.auction.is_leading', true)
            ->assertJsonPath('data.auction.bids_count', 1)
            ->assertJsonStructure(['meta' => ['server_time']])
            ->assertJsonMissingPath('data.auction.leader_id');
    }

    public function test_assignment_example_bid_below_25500_is_rejected_with_the_minimum(): void
    {
        $auction = $this->liveAuction(['starting_price' => 10_000]);
        $this->bid($auction, 25_000, User::factory()->create())->assertCreated();

        $this->bid($auction, 25_499)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'BID_TOO_LOW')
            ->assertJsonPath('error.details.min_next_bid', 25_500);

        $this->bid($auction, 25_500)->assertCreated()->assertJsonPath('data.auction.current_price', 25_500);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'missing' => [null],
            'zero' => [0],
            'negative' => [-500],
            'fractional' => [20_000.5],
            'numeric string' => ['20000'],
            'text' => ['lots'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amount_is_rejected_as_invalid_bid(mixed $amount): void
    {
        $auction = $this->liveAuction();

        $this->bid($auction, $amount)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_BID')
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['amount']]]]);

        $this->assertSame(0, Bid::count());
    }

    public function test_leader_cannot_outbid_themselves(): void
    {
        $auction = $this->liveAuction();
        $this->bid($auction, 20_000)->assertCreated();

        $this->bid($auction, 25_000)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_BID')
            ->assertJsonPath('error.details.reason', 'ALREADY_LEADING');
    }

    public function test_auction_not_started(): void
    {
        $auction = Auction::factory()->scheduled()->create();

        $this->bid($auction, $auction->starting_price)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'AUCTION_NOT_STARTED');
    }

    public function test_auction_already_ended(): void
    {
        $auction = $this->liveAuction();
        $this->travelTo($auction->end_at->addSecond());

        $this->bid($auction, 20_000)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'AUCTION_ENDED');
    }

    public function test_cancelled_auction_is_not_live(): void
    {
        $auction = Auction::factory()->cancelled()->create();

        $this->bid($auction, 1_000_000)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'AUCTION_NOT_LIVE');
    }

    public function test_unknown_auction_returns_auction_not_found(): void
    {
        $this->bid(999_999, 20_000)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'AUCTION_NOT_FOUND');
    }

    public function test_draft_auction_is_hidden_from_bidders(): void
    {
        $draft = Auction::factory()->create();

        $this->bid($draft, $draft->starting_price)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'AUCTION_NOT_FOUND');
    }

    public function test_admins_cannot_bid(): void
    {
        $auction = $this->liveAuction();

        $this->bid($auction, 20_000, User::factory()->admin()->create())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertSame(0, Bid::count());
    }

    public function test_guests_cannot_bid(): void
    {
        $auction = $this->liveAuction();

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/auctions/{$auction->id}/bids", ['amount' => 20_000])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_retrying_with_the_same_key_places_the_bid_only_once(): void
    {
        $auction = $this->liveAuction();
        $key = (string) Str::uuid();

        $first = $this->bid($auction, 20_000, key: $key)->assertCreated();
        $retry = $this->bid($auction, 20_000, key: $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, Bid::count());
        $this->assertSame($first->json('data.bid.id'), $retry->json('data.bid.id'));
    }

    public function test_bid_requires_an_idempotency_key(): void
    {
        $auction = $this->liveAuction();

        $this->actingAs($this->bidder)
            ->postJson("/api/auctions/{$auction->id}/bids", ['amount' => 20_000])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(0, Bid::count());
    }

    public function test_bidding_is_rate_limited_per_user(): void
    {
        $auction = $this->liveAuction();

        for ($i = 0; $i < 30; $i++) {
            $this->bid($auction, 100); // rejected as too low, but still counted
        }

        $this->bid($auction, 20_000)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');

        $this->assertSame(0, Bid::count());
    }
}

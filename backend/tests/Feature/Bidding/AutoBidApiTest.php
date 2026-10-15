<?php

namespace Tests\Feature\Bidding;

use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutoBidApiTest extends TestCase
{
    use RefreshDatabase;

    private Auction $auction;

    private User $a;

    private User $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auction = Auction::factory()->live()->create([
            'starting_price' => 10_000,
            'reserve_price' => null,
            'min_increment' => 500,
        ]);
        [$this->a, $this->b] = User::factory()->count(2)->create()->all();
    }

    private function setMax(User $user, mixed $max, ?string $key = null): TestResponse
    {
        return $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->putJson("/api/auctions/{$this->auction->id}/auto-bid", ['max_amount' => $max]);
    }

    public function test_bidder_sets_a_maximum_and_opens_at_the_starting_price(): void
    {
        $this->setMax($this->a, 20_000)
            ->assertOk()
            ->assertJsonPath('data.auto_bid.max_amount', 20_000)
            ->assertJsonPath('data.auto_bid.is_active', true)
            ->assertJsonPath('data.auction.current_price', 10_000)
            ->assertJsonPath('data.auction.is_leading', true)
            ->assertJsonStructure(['meta' => ['server_time']]);
    }

    public function test_assignment_example_through_the_api(): void
    {
        $this->setMax($this->a, 20_000)->assertOk();
        $this->travel(1)->seconds();

        $this->setMax($this->b, 15_000)
            ->assertOk()
            ->assertJsonPath('data.auction.current_price', 15_500)
            ->assertJsonPath('data.auction.is_leading', false)
            ->assertJsonPath('data.auto_bid.is_active', false);

        $this->actingAs($this->a)->getJson("/api/auctions/{$this->auction->id}")
            ->assertJsonPath('data.current_price', 15_500)
            ->assertJsonPath('data.is_leading', true);
    }

    public function test_the_leaders_maximum_never_reaches_another_bidder(): void
    {
        $this->setMax($this->a, 20_000)->assertOk();
        $this->travel(1)->seconds();

        $responses = [
            $this->setMax($this->b, 15_000),
            $this->actingAs($this->b)->getJson("/api/auctions/{$this->auction->id}"),
            $this->actingAs($this->b)->getJson('/api/auctions'),
            $this->actingAs($this->b)->getJson("/api/auctions/{$this->auction->id}/auto-bid"),
            $this->actingAs($this->b)->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson("/api/auctions/{$this->auction->id}/bids", ['amount' => 16_000]),
        ];

        foreach ($responses as $response) {
            $this->assertStringNotContainsString('20000', $response->getContent(), 'A\'s private maximum leaked.');
        }
    }

    public function test_bidder_reads_only_their_own_auto_bid(): void
    {
        $this->setMax($this->a, 20_000)->assertOk();

        $this->actingAs($this->a)->getJson("/api/auctions/{$this->auction->id}/auto-bid")
            ->assertOk()
            ->assertJsonPath('data.max_amount', 20_000);

        $this->actingAs($this->b)->getJson("/api/auctions/{$this->auction->id}/auto-bid")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_a_manual_bid_that_is_immediately_outbid_still_succeeds_but_does_not_lead(): void
    {
        $this->setMax($this->a, 20_000)->assertOk();

        $this->actingAs($this->b)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/auctions/{$this->auction->id}/bids", ['amount' => 12_000])
            ->assertCreated()
            ->assertJsonPath('data.bid.amount', 12_000)
            ->assertJsonPath('data.auction.current_price', 12_500)
            ->assertJsonPath('data.auction.is_leading', false)
            ->assertJsonPath('data.auction.min_next_bid', 13_000);
    }

    public function test_maximum_below_the_next_valid_bid_is_rejected(): void
    {
        $this->setMax($this->a, 20_000)->assertOk();

        $this->setMax($this->b, 10_200)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'MAX_AUTO_BID_REACHED')
            ->assertJsonPath('error.details.min_next_bid', 10_500);
    }

    public function test_leader_cannot_lower_their_maximum(): void
    {
        $this->setMax($this->a, 20_000)->assertOk();

        $this->setMax($this->a, 15_000)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'MAX_AUTO_BID_REACHED')
            ->assertJsonPath('error.details.minimum_max_amount', 20_000);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidMaximums(): array
    {
        return ['missing' => [null], 'zero' => [0], 'string' => ['20000'], 'fraction' => [20_000.5]];
    }

    #[DataProvider('invalidMaximums')]
    public function test_malformed_maximum_is_an_invalid_bid(mixed $max): void
    {
        $this->setMax($this->a, $max)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_BID');

        $this->assertSame(0, AutoBid::count());
    }

    public function test_retrying_the_same_request_configures_only_once(): void
    {
        $key = (string) Str::uuid();

        $this->setMax($this->a, 20_000, $key)->assertOk();
        $this->setMax($this->a, 20_000, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, $this->auction->bids()->count());
    }

    public function test_admins_cannot_configure_auto_bids(): void
    {
        $this->setMax(User::factory()->admin()->create(), 20_000)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_drafts_are_not_found(): void
    {
        $draft = Auction::factory()->create();

        $this->actingAs($this->a)->getJson("/api/auctions/{$draft->id}/auto-bid")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'AUCTION_NOT_FOUND');
    }
}

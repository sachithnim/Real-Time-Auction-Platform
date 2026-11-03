<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Auction;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuctionLifecycleService;
use App\Services\Bidding\BidService;
use App\Services\Lifecycle\AuctionCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyBidsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $asha;

    private User $ravi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asha = User::factory()->create();
        $this->ravi = User::factory()->create();
    }

    private function live(array $attributes = []): Auction
    {
        return Auction::factory()->live()->create(['starting_price' => 10_000, 'reserve_price' => null, ...$attributes]);
    }

    private function bid(Auction $auction, User $user, int $amount): void
    {
        app(BidService::class)->placeBid($auction, $user, $amount);
    }

    private function close(Auction $auction): void
    {
        $auction->forceFill(['end_at' => now()->subSecond()])->save();
        app(AuctionCloser::class)->close($auction->id);
    }

    private function myBids(string $query = '', ?User $user = null)
    {
        return $this->actingAs($user ?? $this->asha)->getJson('/api/me/bids'.$query);
    }

    public function test_it_lists_only_auctions_the_bidder_has_bid_on(): void
    {
        $mine = $this->live();
        $this->bid($mine, $this->asha, 10_000);
        $this->bid($this->live(), $this->ravi, 10_000);
        $this->live();

        $this->myBids()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.my_bid.state', 'LEADING')
            ->assertJsonPath('data.0.my_bid.highest_bid', 10_000)
            ->assertJsonPath('data.0.my_bid.bids_count', 1)
            ->assertJsonStructure(['meta' => ['server_time', 'total'], 'counts']);
    }

    public function test_a_bidder_who_was_passed_is_outbid(): void
    {
        $auction = $this->live();
        $this->bid($auction, $this->asha, 10_000);
        $this->bid($auction, $this->ravi, 12_000);

        $this->myBids()
            ->assertJsonPath('data.0.my_bid.state', 'OUTBID')
            ->assertJsonPath('data.0.my_bid.highest_bid', 10_000)
            ->assertJsonPath('data.0.leader', 'Bidder 2');
    }

    public function test_the_bidder_sees_their_own_maximum_and_no_one_elses(): void
    {
        $auction = $this->live();
        app(BidService::class)->configureAutoBid($auction, $this->asha, 20_000);
        app(BidService::class)->configureAutoBid($auction, $this->ravi, 15_000);

        $this->myBids()
            ->assertJsonPath('data.0.my_bid.state', 'LEADING')
            ->assertJsonPath('data.0.my_bid.max_amount', 20_000)
            ->assertJsonPath('data.0.my_bid.max_active', true)
            ->assertJsonMissing(['max_amount' => 15_000])
            ->assertJsonMissingPath('data.0.reserve_price');

        $this->myBids(user: $this->ravi)
            ->assertJsonPath('data.0.my_bid.state', 'OUTBID')
            ->assertJsonPath('data.0.my_bid.max_amount', 15_000)
            ->assertJsonPath('data.0.my_bid.max_active', false)
            ->assertJsonMissing(['max_amount' => 20_000]);
    }

    public function test_closed_auctions_are_won_or_lost_and_the_win_shows_its_payment(): void
    {
        $won = $this->live();
        $this->bid($won, $this->asha, 12_000);
        $this->close($won);

        $lost = $this->live();
        $this->bid($lost, $this->asha, 10_000);
        $this->bid($lost, $this->ravi, 11_000);
        $this->close($lost);

        $response = $this->myBids()->assertJsonCount(2, 'data');
        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertSame('WON', $byId[$won->id]['my_bid']['state']);
        $this->assertSame('PENDING', $byId[$won->id]['my_bid']['payment_status']);
        $this->assertSame('LOST', $byId[$lost->id]['my_bid']['state']);
        $this->assertNull($byId[$lost->id]['my_bid']['payment_status']);
    }

    public function test_the_highest_bid_below_the_reserve_is_lost(): void
    {
        $auction = $this->live(['reserve_price' => 50_000]);
        $this->bid($auction, $this->asha, 47_000);
        $this->close($auction);

        $this->myBids()
            ->assertJsonPath('data.0.my_bid.state', 'LOST')
            ->assertJsonPath('data.0.result', 'RESERVE_NOT_MET')
            ->assertJsonPath('data.0.is_leading', true);
    }

    public function test_a_cancelled_auction_says_so(): void
    {
        $auction = $this->live();
        $this->bid($auction, $this->asha, 10_000);
        app(AuctionLifecycleService::class)->cancel($auction, User::factory()->admin()->create());

        $this->myBids()->assertJsonPath('data.0.my_bid.state', 'CANCELLED');
    }

    public function test_tabs_filter_the_list_and_counts_cover_every_tab(): void
    {
        $leading = $this->live();
        $this->bid($leading, $this->asha, 10_000);

        $outbid = $this->live();
        $this->bid($outbid, $this->asha, 10_000);
        $this->bid($outbid, $this->ravi, 11_000);

        $paid = $this->live();
        $this->bid($paid, $this->asha, 10_000);
        $this->close($paid);
        Payment::where('auction_id', $paid->id)->update(['status' => PaymentStatus::Paid]);

        $unpaid = $this->live();
        $this->bid($unpaid, $this->asha, 10_000);
        $this->close($unpaid);

        $lost = $this->live();
        $this->bid($lost, $this->asha, 10_000);
        $this->bid($lost, $this->ravi, 11_000);
        $this->close($lost);

        $ids = fn (string $query) => collect($this->myBids($query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$leading->id, $outbid->id], $ids('?filter=active'));
        $this->assertSame([$paid->id, $unpaid->id], $ids('?filter=won'));
        $this->assertSame([$lost->id], $ids('?filter=lost'));

        $this->myBids()->assertJsonPath('counts', ['all' => 5, 'active' => 2, 'won' => 2, 'lost' => 1, 'to_pay' => 1]);
    }

    public function test_live_auctions_come_first_ending_soonest(): void
    {
        $finished = $this->live();
        $this->bid($finished, $this->asha, 10_000);
        $this->close($finished);

        $later = $this->live(['end_at' => now()->addHours(5)]);
        $this->bid($later, $this->asha, 10_000);
        $sooner = $this->live(['end_at' => now()->addHour()]);
        $this->bid($sooner, $this->asha, 10_000);

        $this->assertSame(
            [$sooner->id, $later->id, $finished->id],
            collect($this->myBids()->json('data'))->pluck('id')->all(),
        );
    }

    public function test_an_unknown_filter_is_a_validation_error(): void
    {
        $this->myBids('?filter=everything')->assertUnprocessable();
    }

    public function test_guests_and_admins_cannot_see_my_bids(): void
    {
        $this->getJson('/api/me/bids')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->getJson('/api/me/bids')->assertForbidden();
    }

    public function test_a_bidder_with_no_bids_gets_an_empty_list_and_zero_counts(): void
    {
        $this->myBids()
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('counts', ['all' => 0, 'active' => 0, 'won' => 0, 'lost' => 0, 'to_pay' => 0]);
    }
}

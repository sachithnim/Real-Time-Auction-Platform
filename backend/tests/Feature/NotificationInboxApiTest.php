<?php

namespace Tests\Feature;

use App\Events\User\AuctionWon;
use App\Events\User\Outbid;
use App\Models\Auction;
use App\Models\Notice;
use App\Models\User;
use App\Services\Bidding\BidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class NotificationInboxApiTest extends TestCase
{
    use RefreshDatabase;

    private User $asha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asha = User::factory()->create();
    }

    private function outbid(int $auctionId, int $price, ?User $user = null): void
    {
        Outbid::dispatch(($user ?? $this->asha)->id, now()->toIso8601ZuluString(), [
            'auction_id' => $auctionId, 'title' => "Auction {$auctionId}", 'current_price' => $price, 'min_next_bid' => $price + 500,
        ]);
    }

    private function inbox(?User $user = null)
    {
        return $this->actingAs($user ?? $this->asha)->getJson('/api/me/notifications');
    }

    public function test_a_real_outbid_lands_in_the_inbox(): void
    {
        $auction = Auction::factory()->live()->create(['starting_price' => 10_000, 'reserve_price' => null]);
        app(BidService::class)->placeBid($auction, $this->asha, 10_000);
        app(BidService::class)->placeBid($auction, User::factory()->create(), 12_000);

        $this->inbox()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'Outbid')
            ->assertJsonPath('data.0.data.auction_id', $auction->id)
            ->assertJsonPath('data.0.data.current_price', 12_000)
            ->assertJsonPath('data.0.read', false)
            ->assertJsonPath('meta.unread_count', 1);
    }

    public function test_repeated_unread_outbids_on_one_auction_become_one_up_to_date_entry(): void
    {
        $this->outbid(1, 12_000);
        $this->travel(1)->minutes();
        $this->outbid(2, 5_000);
        $this->travel(1)->minutes();
        $this->outbid(1, 14_000);

        $response = $this->inbox()->assertJsonCount(2, 'data')->assertJsonPath('meta.unread_count', 2);

        // The updated one moves back to the top, with the latest price.
        $response->assertJsonPath('data.0.data.auction_id', 1)->assertJsonPath('data.0.data.current_price', 14_000);
    }

    public function test_once_read_a_new_outbid_starts_a_new_entry(): void
    {
        $this->outbid(1, 12_000);
        $this->actingAs($this->asha)->postJson('/api/me/notifications/read-all')->assertNoContent();
        $this->outbid(1, 14_000);

        $this->inbox()->assertJsonCount(2, 'data')->assertJsonPath('meta.unread_count', 1);
    }

    public function test_one_entry_or_all_can_be_marked_read(): void
    {
        $this->outbid(1, 12_000);
        AuctionWon::dispatch($this->asha->id, now()->toIso8601ZuluString(), ['auction_id' => 2, 'title' => 'Watch', 'final_price' => 9_000]);
        $won = $this->asha->notifications()->where('type', 'AuctionWon')->sole();

        $this->actingAs($this->asha)->postJson("/api/me/notifications/{$won->id}/read")->assertNoContent();
        $this->inbox()->assertJsonPath('meta.unread_count', 1);

        $this->actingAs($this->asha)->postJson('/api/me/notifications/read-all')->assertNoContent();
        $this->inbox()->assertJsonPath('meta.unread_count', 0);
    }

    public function test_a_bidder_cannot_see_or_mark_someone_elses_notices(): void
    {
        $ravi = User::factory()->create();
        $this->outbid(1, 12_000, $ravi);
        $theirs = $ravi->notifications()->sole();

        $this->inbox()->assertJsonCount(0, 'data');
        $this->actingAs($this->asha)->postJson("/api/me/notifications/{$theirs->id}/read")->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_disabled_accounts_get_no_inbox_entries(): void
    {
        $this->asha->forceFill(['disabled_at' => now()])->save();
        $this->outbid(1, 12_000);

        $this->assertSame(0, Notice::count());
    }

    public function test_old_notices_are_pruned(): void
    {
        $this->outbid(1, 12_000);
        $this->travel(Notice::KEEP_DAYS + 1)->days();
        $this->outbid(2, 5_000);

        Artisan::call('model:prune', ['--model' => [Notice::class]]);

        $this->assertSame([2], $this->asha->notifications()->get()->pluck('data.auction_id')->all());
    }

    public function test_only_signed_in_bidders_have_an_inbox(): void
    {
        $this->getJson('/api/me/notifications')->assertUnauthorized();
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/me/notifications')->assertForbidden();
    }
}

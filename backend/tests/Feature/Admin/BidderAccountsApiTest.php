<?php

namespace Tests\Feature\Admin;

use App\Enums\AuctionEventType;
use App\Events\User\Outbid;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\AutoBid;
use App\Models\User;
use App\Services\Bidding\BidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class BidderAccountsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $asha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->asha = User::factory()->create(['name' => 'Asha Perera', 'email' => 'asha@example.com']);
    }

    private function disable(User $user)
    {
        return $this->actingAs($this->admin)->postJson("/api/admin/bidders/{$user->id}/disable");
    }

    private function login(string $password = 'password')
    {
        return $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/auth/login', ['email' => 'asha@example.com', 'password' => $password]);
    }

    // --- Listing ---------------------------------------------------------------------------

    public function test_admins_list_bidders_with_their_bids_and_wins(): void
    {
        $auction = Auction::factory()->live()->create(['starting_price' => 10_000, 'reserve_price' => null]);
        app(BidService::class)->placeBid($auction, $this->asha, 10_000);
        Auction::factory()->completed()->create(['winner_id' => $this->asha->id]);

        $this->actingAs($this->admin)->getJson('/api/admin/bidders')
            ->assertOk()
            ->assertJsonCount(1, 'data') // admins are not listed
            ->assertJsonPath('data.0.email', 'asha@example.com')
            ->assertJsonPath('data.0.bids_count', 1)
            ->assertJsonPath('data.0.wins_count', 1)
            ->assertJsonPath('data.0.disabled_at', null);
    }

    public function test_bidders_can_be_searched_and_filtered_by_status(): void
    {
        User::factory()->create(['name' => 'Ravi Kumar', 'email' => 'ravi@example.com', 'disabled_at' => now()]);

        $emails = fn (string $query) => collect($this->actingAs($this->admin)->getJson('/api/admin/bidders'.$query)->json('data'))->pluck('email')->all();

        $this->assertSame(['ravi@example.com'], $emails('?search=kumar'));
        $this->assertSame(['asha@example.com'], $emails('?search=asha%40'));
        $this->assertSame(['ravi@example.com'], $emails('?status=disabled'));
        $this->assertSame(['asha@example.com'], $emails('?status=active'));
    }

    // --- Disabling ---------------------------------------------------------------------------

    public function test_a_disabled_bidder_cannot_log_in_and_is_told_why_only_with_the_right_password(): void
    {
        $this->disable($this->asha)->assertOk()->assertJsonPath('data.disabled_at', fn ($at) => $at !== null);

        $this->login()->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
        // A wrong password reads like any failed login: it reveals nothing about the account.
        $this->login('wrong-password')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_an_open_session_ends_on_the_next_request(): void
    {
        $this->asha->forceFill(['disabled_at' => now()])->save();

        $this->actingAs($this->asha)->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_DISABLED');

        $auction = Auction::factory()->live()->create(['starting_price' => 10_000]);
        $this->actingAs($this->asha)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/auctions/{$auction->id}/bids", ['amount' => 10_000])
            ->assertForbidden();
        $this->assertSame(0, $auction->bids()->count());
    }

    public function test_disabling_signs_out_every_device(): void
    {
        config(['session.driver' => 'database']);
        $this->asha->forceFill(['remember_token' => 'old-token'])->save();
        DB::table('sessions')->insert(['id' => 'ashas-phone', 'user_id' => $this->asha->id, 'payload' => '', 'last_activity' => time()]);

        $this->disable($this->asha)->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'ashas-phone']);
        $this->assertNotSame('old-token', $this->asha->fresh()->remember_token);
    }

    public function test_disabling_stops_every_private_maximum_and_records_it(): void
    {
        $leading = Auction::factory()->live()->create(['starting_price' => 10_000, 'reserve_price' => null]);
        $outbid = Auction::factory()->live()->create(['starting_price' => 10_000, 'reserve_price' => null]);
        $ravi = User::factory()->create();
        $bids = app(BidService::class);
        $bids->configureAutoBid($leading, $this->asha, 20_000);
        $bids->configureAutoBid($outbid, $this->asha, 30_000);
        $bids->configureAutoBid($outbid, $ravi, 50_000);

        $this->disable($this->asha)->assertOk();

        $this->assertSame(0, AutoBid::where('user_id', $this->asha->id)->where('is_active', true)->count());
        $event = AuctionEvent::where('auction_id', $leading->id)->where('type', AuctionEventType::AutoBidCancelled)->sole();
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertEquals(['user_id' => $this->asha->id, 'max_amount' => 20_000, 'reason' => 'ACCOUNT_DISABLED'], $event->payload);

        // Her bid stays (history is never rewritten), but nothing defends it any more.
        $bids->placeBid($leading->fresh(), $ravi, 10_500);
        $this->assertSame($ravi->id, $leading->fresh()->leader_id);
        $this->assertSame(10_500, $leading->fresh()->current_price);
    }

    public function test_disabling_twice_is_harmless_and_enabling_lets_them_log_in(): void
    {
        $this->disable($this->asha)->assertOk();
        $this->disable($this->asha)->assertOk();

        $this->actingAs($this->admin)->postJson("/api/admin/bidders/{$this->asha->id}/enable")
            ->assertOk()
            ->assertJsonPath('data.disabled_at', null);

        $this->login()->assertOk();
    }

    public function test_a_disabled_bidder_gets_no_notification_emails(): void
    {
        Notification::fake();
        $this->asha->forceFill(['disabled_at' => now()])->save();

        Outbid::dispatch($this->asha->id, now()->toIso8601ZuluString(), ['auction_id' => 1, 'title' => 'Telescope', 'current_price' => 1, 'min_next_bid' => 2]);

        Notification::assertNothingSent();
    }

    public function test_admin_accounts_cannot_be_disabled(): void
    {
        $other = User::factory()->admin()->create();

        $this->disable($other)->assertForbidden();
        $this->disable($this->admin)->assertForbidden();
        $this->assertNull($other->fresh()->disabled_at);
    }

    public function test_bidders_cannot_manage_accounts(): void
    {
        $this->actingAs($this->asha)->getJson('/api/admin/bidders')->assertForbidden();
        $this->actingAs($this->asha)->postJson("/api/admin/bidders/{$this->asha->id}/enable")->assertForbidden();
    }
}

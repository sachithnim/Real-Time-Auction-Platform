<?php

namespace Tests\Feature\Monitoring;

use App\Enums\FlagRule;
use App\Enums\FlagStatus;
use App\Models\Auction;
use App\Models\BidFlag;
use App\Models\User;
use App\Services\Monitoring\SuspiciousBidDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SuspiciousBiddingApiTest extends TestCase
{
    use RefreshDatabase;

    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();

        // Run the after-response scan immediately, so each request's effect is visible.
        $this->withoutDefer();
        $this->auction = Auction::factory()->live()->create([
            'starting_price' => 10_000, 'reserve_price' => null, 'min_increment' => 500,
        ]);
    }

    private function bid(User $user, int $amount, string $ip = '203.0.113.7')
    {
        return $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/auctions/{$this->auction->id}/bids", ['amount' => $amount]);
    }

    private function flag(FlagRule $rule, array $attributes = []): BidFlag
    {
        $bidder = User::factory()->create();

        return BidFlag::forceCreate([
            'dedupe_key' => Str::uuid()->toString(), 'auction_id' => $this->auction->id, 'rule' => $rule,
            'user_id' => $bidder->id, 'details' => ['bids' => 10], 'status' => FlagStatus::Open, 'occurrences' => 1,
            'first_detected_at' => now(), 'last_detected_at' => now(), ...$attributes,
        ]);
    }

    // --- Detection through the bid endpoints ---------------------------------------

    public function test_bids_from_two_accounts_on_one_ip_are_flagged_but_both_accepted(): void
    {
        [$a, $b] = User::factory()->count(2)->create()->all();

        $this->bid($a, 10_000)->assertCreated();
        $this->bid($b, 10_500)->assertCreated();

        $this->assertSame(FlagRule::SharedIp, BidFlag::sole()->rule);
        $this->assertSame(2, DB::table('bidder_ips')->where('ip_address', '203.0.113.7')->count());
    }

    public function test_rejected_bids_are_scanned_and_a_flagged_bidder_can_still_bid(): void
    {
        $bidder = User::factory()->create();
        $this->bid(User::factory()->create(), 10_000, '198.51.100.1')->assertCreated();

        foreach (range(1, 5) as $i) {
            $this->bid($bidder, 1)->assertUnprocessable()->assertJsonPath('error.code', 'BID_TOO_LOW');
        }
        $this->assertSame(FlagRule::ManyRejections, BidFlag::sole()->rule);

        // A flag is a signal for an admin, never a block.
        $this->bid($bidder, 10_500)->assertCreated();
    }

    public function test_a_failing_scan_never_breaks_the_bid(): void
    {
        $this->mock(SuspiciousBidDetector::class)->shouldReceive('scan')->andThrow(new RuntimeException('Detector down'));

        $this->bid(User::factory()->create(), 10_000)->assertCreated();
    }

    // --- Admin review ------------------------------------------------------------------

    public function test_admins_list_open_flags_first_with_the_accounts_involved(): void
    {
        $reviewed = $this->flag(FlagRule::RapidBidding, ['status' => FlagStatus::Reviewed, 'last_detected_at' => now()]);
        $open = $this->flag(FlagRule::ManyRejections, ['last_detected_at' => now()->subHour()]);

        $response = $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/flags')->assertOk();

        $this->assertSame([$open->id, $reviewed->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta.open_count', 1)
            ->assertJsonPath('data.0.rule', 'MANY_REJECTIONS')
            ->assertJsonPath('data.0.auction.title', $this->auction->title)
            ->assertJsonPath('data.0.bidders.0.email', $open->user->email);
    }

    public function test_admins_filter_flags_by_status(): void
    {
        $this->flag(FlagRule::RapidBidding, ['status' => FlagStatus::Reviewed]);
        $open = $this->flag(FlagRule::SharedIp);

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/admin/flags?status=OPEN')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id);
    }

    public function test_an_admin_marks_a_flag_reviewed(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Platform Admin']);
        $flag = $this->flag(FlagRule::RapidBidding);

        $this->actingAs($admin)->postJson("/api/admin/flags/{$flag->id}/review")
            ->assertOk()
            ->assertJsonPath('data.status', 'REVIEWED')
            ->assertJsonPath('data.reviewed_by.name', 'Platform Admin');

        $this->assertSame(FlagStatus::Reviewed, $flag->fresh()->status);
        $this->actingAs($admin)->postJson('/api/admin/flags/999999/review')->assertNotFound();
    }

    public function test_bidders_and_guests_cannot_see_or_review_flags(): void
    {
        $flag = $this->flag(FlagRule::SharedIp);
        $bidder = User::factory()->create();

        $this->actingAs($bidder)->getJson('/api/admin/flags')->assertForbidden();
        $this->actingAs($bidder)->postJson("/api/admin/flags/{$flag->id}/review")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/flags')->assertUnauthorized();

        $this->assertSame(FlagStatus::Open, $flag->fresh()->status);
    }
}
